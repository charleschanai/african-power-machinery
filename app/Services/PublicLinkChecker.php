<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Fetches a shared address and reports what a guest would actually get.
 *
 * This is kept out of the request cycle on purpose. The address a guest is
 * given normally points back at this same machine through a tunnel, so a
 * request that fetches it is asking the server to serve a second request while
 * it is still busy with the first. php artisan serve answers one request at a
 * time, so the second waits for the first, which is waiting for the second, and
 * the check runs all the way to its timeout while the admin's page hangs with
 * it. Nothing in a page load is worth that.
 *
 * So the checks that need no network stay in the model and run on every load,
 * and this one is run from the command line, where the server is free to answer.
 * The answer is stored, and the page reads the stored one.
 */
class PublicLinkChecker
{
    /**
     * Markers of the notice a free tunnel may put in front of the real app.
     *
     * A tunnel that shows its notice only to browsers also skips it for anything
     * that does not look like a browser, and the request below sends a plain
     * User-Agent, so on those hosts these markers never appear in the body.
     * They are here for a tunnel that shows its notice to everything.
     *
     * Recognised so it can be described honestly, not so it can be blocked.
     */
    private const WARNING_MARKERS = [
        'ERR_NGROK_6024',
        'You are about to visit',
        'served for free through ngrok.com',
    ];

    /**
     * cURL failures that mean the address itself is not serving.
     *
     * A refused connection or a name that does not resolve is a verdict about
     * the link, and it is the one that stops an SMS being sent. The handshake
     * and timeout failures are deliberately not in here: they are the ones a
     * single machine gets wrong on its own, often because of antivirus
     * inspection, while the same address opens fine for everyone else.
     */
    private const DEAD_ERRNOS = [
        6,  // could not resolve the host
        7,  // tried to connect and was refused
        35, // the TLS handshake itself was refused by a listener that is not a web server
    ];

    /**
     * Fetches the address and says plainly what a guest would meet.
     *
     * A fetch that never completes comes back as unknown rather than broken. A
     * slow handshake on one machine says nothing about what a guest holding a
     * working phone connection will see, and calling that broken would be a lie
     * in the opposite direction.
     *
     * @return array{ok: bool, level: string, reason: string}
     */
    public function check(string $url): array
    {
        $url = rtrim(trim($url), '/');
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '') {
            return self::verdict(false, 'broken', 'No address is configured, so links cannot be built.');
        }

        // The cheap question first: does anything answer on the port at all?
        //
        // A tunnel edge is a public server run by somebody else, so it is
        // reachable from anywhere with a phone signal. If a plain TCP connect
        // cannot even get through, the tunnel is genuinely down and every guest
        // would get an error, which is the one failure that wastes paid SMS and
        // is exactly what happened with the earlier tunnel. This costs one
        // connection and no TLS, so it also succeeds on machines where the
        // handshake below cannot be trusted to tell the truth.
        //
        // The port has to be the one the link actually names, not the default
        // for the scheme, or a link on a closed port looks healthy because
        // something else on the usual port answered.
        $edge = self::edgeAnswers($host, self::portIn($url));

        if (! $edge['answers']) {
            return self::verdict(
                false,
                'broken',
                'Nothing answered on '.$host.' port '.$edge['port'].', so the tunnel is down and no guest can open the link ('
                .$edge['detail'].'). Start the tunnel, then run this command again.'
            );
        }

        try {
            $response = Http::withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
                ->withUserAgent('eCardSystem link check')
                // Resolved over IPv4 only, and on short limits. cURL applies the
                // connect limit once per address it tries, so a host answering
                // on both IPv4 and IPv6 could otherwise spend twice the limit
                // before giving up.
                ->withOptions([
                    'connect_timeout' => 3,
                    'timeout' => 6,
                    'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                ])
                ->get($url);
        } catch (\Throwable $e) {
            $errno = self::curlErrno($e);

            // A refusal or an unresolvable name is a verdict about the link and
            // is the one that must stop an SMS. A timeout or a failed handshake
            // is not, because those are what one machine gets wrong on its own
            // while the same address opens fine for everyone else, and treating
            // them as dead is how an admin ends up tearing out a working tunnel.
            if ($errno !== null && in_array($errno, self::DEAD_ERRNOS, true)) {
                return self::verdict(
                    false,
                    'broken',
                    $host.' is not serving. Nothing accepted a connection for the link ('.trim($e->getMessage()).'), '
                    .'so a guest would get an error instead of the card. Start the tunnel, then run this again.'
                );
            }

            // The edge answered on the previous step, so the tunnel is up. What
            // failed is this machine's own TLS, which is a local problem and not
            // a verdict on the link. Saying so is the whole point: a guest on a
            // phone may open the card perfectly while this machine cannot
            // confirm it, and the admin should not be sent chasing a tunnel
            // that is working.
            return self::verdict(
                true,
                'unknown',
                $host.' is up, because it answered on port '.$edge['port'].' in '.$edge['ms'].' ms. '
                .'What failed is the secure handshake from this machine ('.trim($e->getMessage()).'), '
                .'which is a local problem and not a fault in the link. '
                .'Open it on a phone, on mobile data, before sending any SMS.'
            );
        }

        $body = substr((string) $response->body(), 0, 4000);

        foreach (self::WARNING_MARKERS as $marker) {
            if (stripos($body, $marker) !== false) {
                // Counted as reachable, not as a fault. A guest who taps
                // through the notice does reach the card, so the link works and
                // the Invitations page has no business crying wolf about it. The
                // wording still says plainly what happens, so the admin reading
                // the command output is not misled either.
                return self::verdict(
                    true,
                    'ok',
                    $host.' is up. It answers with a "you are about to visit" notice first, so a guest taps once through it and then sees the card.'
                );
            }
        }

        $status = $response->status();

        if ($status >= 400) {
            return self::verdict(false, 'broken', $host.' answered HTTP '.$status.', so a guest opening the link would see an error.');
        }

        return self::verdict(true, 'ok', $host.' is serving the app, so links will open on a guest phone.');
    }

    /**
     * The port a link really points at.
     *
     * A port written into the address wins over the default for its scheme,
     * because that is the port the guest's browser will use, and the check has
     * to ask about the same one.
     */
    private static function portIn(string $url): int
    {
        $port = parse_url($url, PHP_URL_PORT);

        if (is_int($port) && $port > 0) {
            return $port;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http' ? 80 : 443;
    }

    /**
     * The cURL error number behind a failed request, read out of its message.
     *
     * The request is made by Guzzle, which keeps the cURL handle to itself, so
     * there is no handle to ask. Guzzle does put the number in the message it
     * throws, in the form "cURL error 28: ...", which is the one place it is
     * available from here.
     *
     * A failure with no number in it, such as a malformed URL, is not a
     * connection verdict, and returning null keeps it from being read as one.
     */
    private static function curlErrno(\Throwable $e): ?int
    {
        if (preg_match('/cURL error (\d+)/i', $e->getMessage(), $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @return array{ok: bool, level: string, reason: string}
     */
    public static function verdict(bool $ok, string $level, string $reason): array
    {
        return ['ok' => $ok, 'level' => $level, 'reason' => $reason];
    }

    /**
     * Opens a plain TCP connection to the host, with no TLS involved.
     *
     * The point of doing it this way is that it isolates one question from
     * another. "Is the tunnel running" and "can this machine complete a secure
     * handshake" are separate questions, and on this machine only the second
     * one fails. Asking the first one on its own gives a verdict that can be
     * trusted, which the full fetch could not.
     *
     * @return array{answers: bool, port: int, ms: int, detail: string}
     */
    private static function edgeAnswers(string $host, int $port): array
    {
        $errno = 0;
        $errstr = '';
        $started = microtime(true);

        $socket = @stream_socket_client(
            'tcp://'.$host.':'.$port,
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT
        );

        $ms = (int) round((microtime(true) - $started) * 1000);

        if ($socket === false) {
            $detail = $errstr !== '' ? $errstr.' (code '.$errno.')' : 'code '.$errno;

            return ['answers' => false, 'port' => $port, 'ms' => $ms, 'detail' => $detail];
        }

        fclose($socket);

        return ['answers' => true, 'port' => $port, 'ms' => $ms, 'detail' => ''];
    }
}
