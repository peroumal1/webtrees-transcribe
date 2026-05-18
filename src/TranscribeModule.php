<?php

declare(strict_types=1);

namespace Peroumal\WebtreesTranscribe;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleTabInterface;
use Fisharebest\Webtrees\Module\ModuleTabTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function response;

class TranscribeModule extends AbstractModule implements ModuleTabInterface, ModuleConfigInterface, RequestHandlerInterface
{
    use ModuleTabTrait;
    use ModuleConfigTrait;

    private const ROUTE_TRANSCRIBE = '/webtrees-transcribe/transcribe';
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
        Registry::routeFactory()->routeMap()
            ->post(static::class, self::ROUTE_TRANSCRIBE, $this);
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
            'route_url'     => route(static::class),
        ]);
    }

    /**
     * Handle POST /webtrees-transcribe/transcribe
     * Receives an uploaded image, sends it to Claude Vision, returns extracted data as JSON.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
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

        $image_data   = base64_encode((string) $uploaded->getStream());
        $mime_type    = $uploaded->getClientMediaType() ?? 'image/jpeg';

        $result = $this->callClaudeVision($api_key, $image_data, $mime_type);

        return response(json_encode($result))
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Admin configuration page.
     */
    public function getConfigLink(): string
    {
        return route(static::class . 'Config');
    }

    /**
     * Send the document image to Claude Vision and return extracted genealogical data.
     *
     * @return array{text: string, names: string[], dates: string[], places: string[]}|array{error: string}
     */
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
                                . '(2) dates (births, marriages, deaths), '
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

        // Claude returns a JSON object inside its text response
        $extracted = json_decode($text, true);

        if (!is_array($extracted)) {
            // Fallback: return raw transcription
            return ['transcription' => $text, 'names' => [], 'dates' => [], 'places' => [], 'relationships' => []];
        }

        return $extracted;
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/../resources/';
    }
}
