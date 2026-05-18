<?php

declare(strict_types=1);

namespace Peroumal1\WebtreesTranscribe;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleTabInterface;
use Fisharebest\Webtrees\Module\ModuleTabTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function redirect;
use function response;
use function route;
use function view;

class TranscribeModule extends AbstractModule implements ModuleCustomInterface, ModuleTabInterface, ModuleConfigInterface, RequestHandlerInterface
{
    use ModuleCustomTrait;
    use ModuleTabTrait;
    use ModuleConfigTrait;

    private const ROUTE_TRANSCRIBE = '/webtrees-transcribe/{tree}/transcribe';
    private const ROUTE_ADD_FACT   = '/webtrees-transcribe/{tree}/{xref}/add-fact';
    private const ROUTE_CONFIG     = '/webtrees-transcribe/config';

    public function title(): string
    {
        return 'Transcribe';
    }

    public function description(): string
    {
        return 'Transcribe historical documents using Claude AI.';
    }

    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

        $map = Registry::routeFactory()->routeMap();
        $map->post(static::class, self::ROUTE_TRANSCRIBE, $this);
        $map->post(static::class . 'AddFact', self::ROUTE_ADD_FACT, $this);
        $map->get(static::class . 'Config', self::ROUTE_CONFIG, $this);
        $map->post(static::class . 'ConfigSave', self::ROUTE_CONFIG, $this);
    }

    public function defaultTabOrder(): int
    {
        return 10;
    }

    public function hasTabContent(Individual $individual): bool
    {
        return Auth::isEditor($individual->tree());
    }

    public function isGrayedOut(Individual $individual): bool
    {
        return false;
    }

    public function canLoadAjax(): bool
    {
        return false;
    }

    public function getTabContent(Individual $individual): string
    {
        return view($this->name() . '::tab', [
            'individual'    => $individual,
            'module'        => $this,
            'api_key_set'   => $this->getPreference('api_key') !== '',
            'route_url'     => route(static::class, ['tree' => $individual->tree()->name()]),
            'add_fact_url'  => route(static::class . 'AddFact', [
                'tree' => $individual->tree()->name(),
                'xref' => $individual->xref(),
            ]),
        ]);
    }

    public function getConfigLink(): string
    {
        return route(static::class . 'Config');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $route      = $request->getAttribute('route');
        $route_name = $route->name ?? '';

        if ($route_name === static::class . 'Config') {
            return $this->showConfig($request);
        }

        if ($route_name === static::class . 'ConfigSave') {
            return $this->saveConfig($request);
        }

        if ($route_name === static::class . 'AddFact') {
            return $this->addFact($request);
        }

        return $this->transcribe($request);
    }

    private function showConfig(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            throw new HttpAccessDeniedException();
        }

        return $this->viewResponse($this->name() . '::config', [
            'module'   => $this,
            'title'    => $this->title(),
            'api_key'  => $this->getPreference('api_key'),
            'save_url' => route(static::class . 'ConfigSave'),
            'tree'     => null,
        ]);
    }

    private function saveConfig(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            throw new HttpAccessDeniedException();
        }

        $api_key = Validator::parsedBody($request)->string('api_key', '');
        $this->setPreference('api_key', trim($api_key));

        FlashMessages::addMessage('API key saved.', 'success');

        return redirect(route(static::class . 'Config'));
    }

    private function addFact(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $xref = Validator::attributes($request)->isXref()->string('xref');

        $individual = Registry::individualFactory()->make($xref, $tree);
        $individual = Auth::checkIndividualAccess($individual, true);

        $fact_type = Validator::parsedBody($request)->string('fact_type', '');
        $date      = trim(Validator::parsedBody($request)->string('date', ''));
        $place     = trim(Validator::parsedBody($request)->string('place', ''));

        $allowed = ['BIRT', 'DEAT', 'BAPM', 'BURI', 'MARR', 'RESI', 'EMIG', 'IMMI', 'NATU', 'CENS'];
        if (!in_array($fact_type, $allowed, true)) {
            return response(json_encode(['error' => 'Invalid fact type.']), 400)
                ->withHeader('Content-Type', 'application/json');
        }

        $gedcom = '1 ' . $fact_type;
        if ($date !== '') {
            $gedcom .= "\n2 DATE " . $date;
        }
        if ($place !== '') {
            $gedcom .= "\n2 PLAC " . $place;
        }

        $individual->createFact($gedcom, true);

        return response(json_encode(['success' => true]))
            ->withHeader('Content-Type', 'application/json');
    }

    private function transcribe(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        Auth::checkComponentAccess($this, ModuleTabInterface::class, $tree, Auth::user());

        $api_key = $this->getPreference('api_key');

        if ($api_key === '') {
            return response(json_encode(['error' => 'API key not configured.']), 400)
                ->withHeader('Content-Type', 'application/json');
        }

        $uploaded = $request->getUploadedFiles()['document'] ?? null;

        if ($uploaded === null || $uploaded->getError() !== UPLOAD_ERR_OK) {
            return response(json_encode(['error' => 'No file uploaded.']), 400)
                ->withHeader('Content-Type', 'application/json');
        }

        $image_data = base64_encode((string) $uploaded->getStream());
        $mime_type  = $uploaded->getClientMediaType() ?? 'image/jpeg';

        $result = $api_key === 'test'
            ? $this->mockResult()
            : $this->callClaudeVision($api_key, $image_data, $mime_type);

        return response(json_encode($result))
            ->withHeader('Content-Type', 'application/json');
    }

    private function mockResult(): array
    {
        return [
            'transcription' => 'Baptismal register, Parish of Sandringham, Norfolk. '
                . 'Albert Frederick Arthur George, born 14 December 1895 at Sandringham House. '
                . 'Son of George Frederick Ernest Albert, Prince of Wales, '
                . 'and Victoria Mary Augusta Louise (Princess Mary of Teck), Princess of Wales. '
                . 'Christened 17 February 1896.',
            'names'         => [
                'Albert Frederick Arthur George',
                'George Frederick Ernest Albert',
                'Victoria Mary Augusta Louise',
            ],
            'dates'         => ['14 DEC 1895', '17 FEB 1896'],
            'places'        => ['Sandringham, Norfolk, England'],
            'relationships' => [
                'Albert Frederick Arthur George is son of George Frederick Ernest Albert',
                'Albert Frederick Arthur George is son of Victoria Mary Augusta Louise',
            ],
        ];
    }

    private function callClaudeVision(string $api_key, string $image_data, string $mime_type): array
    {
        $payload = [
            'model'      => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'image',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => $mime_type,
                                'data'       => $image_data,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'This is a historical genealogical document. '
                                . 'Please transcribe all readable text and extract: '
                                . '(1) full names of people mentioned, '
                                . '(2) dates (births, marriages, deaths) in GEDCOM format (e.g. 14 DEC 1895), '
                                . '(3) places mentioned, '
                                . '(4) family relationships described. '
                                . 'Return a JSON object with keys: "transcription" (full text), '
                                . '"names" (array), "dates" (array), "places" (array), "relationships" (array).',
                        ],
                    ],
                ],
            ],
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $api_key,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status !== 200) {
            return ['error' => 'Claude API request failed (HTTP ' . $status . ').'];
        }

        $body = json_decode((string) $response, true);
        $text = $body['content'][0]['text'] ?? '';

        $extracted = json_decode($text, true);

        if (!is_array($extracted)) {
            return ['transcription' => $text, 'names' => [], 'dates' => [], 'places' => [], 'relationships' => []];
        }

        return $extracted;
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/../resources/';
    }
}
