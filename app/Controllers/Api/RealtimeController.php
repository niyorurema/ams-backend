<?php

namespace App\Controllers\Api;

use App\Services\RealtimeService;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Diffuse les nouveaux pointages au moyen de Server-Sent Events.
 */
final class RealtimeController extends BaseApiController
{
    /**
     * GET /api/realtime/pointages
     *
     * Le flux est borné à 22 secondes afin de libérer régulièrement les workers
     * PHP. Le client se reconnecte ensuite avec le dernier identifiant reçu.
     */
    public function pointages(): ResponseInterface
    {
        
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $curseur = $this->request->getHeaderLine('Last-Event-ID');
        if ($curseur === '' || ! preg_match('/^\\d+-\\d+$/', $curseur)) {
            $curseur = '$';
        }

        set_time_limit(0);
        ignore_user_abort(true);
        ini_set('zlib.output_compression', '0');
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('Content-Encoding: identity');
        header('X-Accel-Buffering: no');
        $origine = $this->request->getHeaderLine('Origin');
        $originesAutorisees = config('Cors')->default['allowedOrigins'] ?? [];
        if ($origine !== '' && in_array($origine, $originesAutorisees, true)) {
            header('Access-Control-Allow-Origin: ' . $origine);
            header('Vary: Origin');
        }
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        echo "retry: 3000\n: connexion établie\n\n";
        flush();
        $debut = time();
        $dernierSignal = $debut;

        try {
            $realtime = new RealtimeService();
            while (time() - $debut < 22 && ! connection_aborted()) {
                $evenements = $realtime->lirePointagesDepuis($tenantId, $curseur);
                foreach ($evenements as $evenement) {
                    $curseur = $evenement['id'];
                    $payload = $realtime->enrichirEvenementPointage($evenement['payload'], $tenantId);
                    log_message('info', '[SSE] Événement lu tenant_id={tenantId} stream_id={streamId}', [
                        'tenantId' => $tenantId,
                        'streamId' => $curseur,
                    ]);
                    echo 'id: ' . $evenement['id'] . "\n";
                    echo 'data: ' . json_encode(
                        $payload,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ) . "\n\n";
                    flush();
                    $dernierSignal = time();
                    if (connection_aborted()) {
                        break 2;
                    }
                }

                if (time() - $dernierSignal >= 10) {
                    echo ": keep-alive\n\n";
                    flush();
                    $dernierSignal = time();
                }
            }
        } catch (Throwable $exception) {
            log_message('error', 'Flux temps réel du tenant #{tenant} interrompu : {message}', [
                'tenant' => $tenantId,
                'message' => $exception->getMessage(),
            ]);
            echo "event: error\ndata: {\"message\":\"Flux temps réel indisponible.\"}\n\n";
            flush();
        }

        exit;
    }
}
