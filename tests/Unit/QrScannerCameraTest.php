<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the mobile QR scanner.
 *
 * The camera failure was silent: on a phone the scanner showed a plausible
 * loading state, no console error, and no message explaining what went wrong.
 * There is no JavaScript test runner in this project, so the camera contract is
 * asserted against the component source instead. Each assertion below maps to a
 * specific defect that made the camera unusable on a real handset.
 */
class QrScannerCameraTest extends TestCase
{
    /** Full component, comments included. Use for template/markup assertions. */
    private function scanner(): string
    {
        $path = dirname(__DIR__, 2).'/resources/js/components/QRScanner.vue';

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The <script> block with comments removed, so that the prose explaining a
     * bug cannot satisfy (or trip) a behavioural assertion.
     */
    private function scannerCode(): string
    {
        $source = $this->scanner();

        $this->assertSame(
            1,
            preg_match('#<script[^>]*>(.*)</script>#s', $source, $matches),
            'The scanner component must have a script block.'
        );

        $code = preg_replace('#/\*.*?\*/#s', '', $matches[1]);
        $code = preg_replace('#^[ \t]*//.*$#m', '', (string) $code);

        return (string) $code;
    }

    /** Extracts a top-level function body so it can be asserted on in isolation. */
    private function functionBody(string $name): string
    {
        $code = $this->scannerCode();

        $start = strpos($code, 'function '.$name.'(');

        $this->assertNotFalse($start, "Expected a $name() function in the scanner.");

        $open = strpos($code, '{', $start);
        $this->assertNotFalse($open);

        $depth = 0;
        $length = strlen($code);

        for ($i = $open; $i < $length; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($code, $open, $i - $open + 1);
                }
            }
        }

        $this->fail("Unbalanced braces while reading $name().");
    }

    /**
     * enumerateDevices() only returns usable device ids *after* the camera
     * permission has been granted. Called beforehand it yields deviceId === '',
     * and `deviceId: { exact: '' }` can never be satisfied, so getUserMedia
     * rejected with OverconstrainedError and the camera never opened on
     * Android or iOS. The component must never pin a device that way.
     */
    public function test_it_never_pins_a_camera_by_device_id_from_enumerate_devices(): void
    {
        $code = $this->scannerCode();

        $this->assertStringNotContainsString(
            'enumerateDevices',
            $code,
            'enumerateDevices() returns empty device ids before permission is granted, '
            .'which is what broke the camera on mobile. Use facingMode instead.'
        );

        $this->assertStringNotContainsString(
            'deviceId',
            $code,
            'A pinned deviceId cannot be resolved before permission is granted.'
        );
    }

    /**
     * `exact` makes a constraint mandatory; a phone only has to offer a rear or
     * front camera, so the preference has to stay `ideal` with a fallback.
     */
    public function test_facing_mode_is_requested_as_a_preference_not_a_demand(): void
    {
        $code = $this->scannerCode();

        $this->assertStringContainsString(
            "facingMode: { ideal: 'environment' }",
            $code,
            'The rear camera must be requested as an ideal.'
        );

        $this->assertStringContainsString(
            "facingMode: { ideal: 'user' }",
            $code,
            'There must be a fallback for devices that only expose the other camera.'
        );

        $this->assertDoesNotMatchRegularExpression(
            "/facingMode:\s*'/",
            $code,
            'A bare facingMode value is a mandatory demand and must not be used.'
        );

        $this->assertStringContainsString('{ audio: false, video: true }', $code);
    }

    /**
     * decodeFromVideoDevice() calls getUserMedia a second time for the same
     * camera and replaces the stream already bound to the <video> element. iOS
     * Safari only tolerates one camera stream, and the first stream leaked.
     * decodeFromStream() re-uses the stream the component already owns.
     */
    public function test_the_camera_is_opened_exactly_once_and_shared_with_the_decoder(): void
    {
        $code = $this->scannerCode();

        $this->assertStringContainsString(
            'decodeFromStream',
            $code,
            'The scanner must hand its own stream to ZXing instead of letting it open a second one.'
        );

        $this->assertStringNotContainsString(
            'decodeFromVideoDevice',
            $code,
            'decodeFromVideoDevice() opens a competing stream and overwrites the preview.'
        );

        $this->assertSame(
            1,
            substr_count($code, 'getUserMedia(constraints)'),
            'getUserMedia should be called once, inside the constraint fallback loop.'
        );

        // The stream has to be adopted before ZXing runs, so that no code path
        // can leave a live track behind with nobody holding a reference to it.
        $this->assertMatchesRegularExpression(
            '/stream = acquired;.*decodeFromStream\(/s',
            $code,
            'The stream must be tracked before it is handed to the decoder.'
        );
    }

    /**
     * iOS refuses to play inline, and shows nothing but a black rectangle,
     * unless playsInline is set. Older iOS only honours the attribute form.
     */
    public function test_the_video_element_is_configured_for_ios_inline_playback(): void
    {
        $source = $this->scanner();

        $this->assertStringContainsString('playsinline', $source);
        $this->assertStringContainsString('muted', $source);

        $body = $this->functionBody('prepareVideoElement');

        $this->assertStringContainsString('video.playsInline = true', $body);
        $this->assertStringContainsString('video.muted = true', $body);
        $this->assertStringContainsString("setAttribute('playsinline', 'true')", $body);
    }

    /**
     * The old catch block set the error status and then immediately called
     * stop(), which overwrote it with "Camera stopped." — so the real reason was
     * never shown. Releasing the camera must not touch the status.
     */
    public function test_releasing_the_camera_does_not_overwrite_the_error_message(): void
    {
        $body = $this->functionBody('releaseCamera');

        $this->assertStringNotContainsString(
            'setStatus(',
            $body,
            'releaseCamera() must only free resources, never change the status.'
        );

        $this->assertStringNotContainsString(
            'STATUS_TEXT',
            $body,
            'releaseCamera() must not write a status message.'
        );

        // It does have to stop the tracks, otherwise the camera light stays on.
        $this->assertStringContainsString('track.stop()', $body);
        $this->assertStringContainsString('srcObject = null', $body);

        // The error path releases resources, but through the non-status helper.
        $this->assertMatchesRegularExpression(
            '/fail\([^;]*\);\s*releaseCamera\(\);/s',
            $this->scannerCode(),
            'Failures must call releaseCamera(), not stop().'
        );
    }

    /**
     * getUserMedia only exists in a secure context. Opening the app over plain
     * http:// on a LAN address is a normal way to test on a phone, and it hides
     * navigator.mediaDevices entirely, which used to be reported as "camera not
     * supported on this device".
     */
    public function test_an_insecure_context_is_detected_and_reported_as_https(): void
    {
        $code = $this->scannerCode();

        $this->assertStringContainsString('window.isSecureContext', $code);
        $this->assertStringContainsString('isSecureContext', $code);

        $open = $this->functionBody('openCamera');
        $this->assertStringContainsString('!window.isSecureContext', $open);

        $this->assertMatchesRegularExpression(
            "/insecure:\s*'[^']*HTTPS/",
            $code,
            'The insecure-context message must tell the user that HTTPS is required.'
        );
    }

    /**
     * A single generic "camera error" is useless to gate staff. The browser
     * error names have to be mapped to distinct, actionable messages.
     */
    public function test_camera_failures_are_reported_with_specific_reasons(): void
    {
        $code = $this->scannerCode();

        foreach (
            [
                'NotAllowedError' => 'permission denied',
                'NotFoundError' => 'no camera present',
                'NotReadableError' => 'camera busy',
                'OverconstrainedError' => 'constraints unsatisfiable',
                'SecurityError' => 'insecure context',
            ] as $name => $why
        ) {
            $this->assertStringContainsString(
                $name,
                $this->functionBody('toErrorKind'),
                "The $why case ($name) has to be recognised."
            );
        }

        // Every kind that toErrorKind() can return needs a message, or the user
        // sees the raw key instead of an explanation.
        $mapped = [
            'denied',
            'insecure',
            'notfound',
            'busy',
            'overconstrained',
        ];

        foreach ($mapped as $kind) {
            $this->assertMatchesRegularExpression(
                "/\n\s+".$kind.":\s*\n?\s*'/",
                $code,
                "ERROR_TEXT must describe the '$kind' case."
            );
        }
    }

    /**
     * Retrying cannot fix a missing secure context or a browser with no camera
     * API, so offering "Try Again" there only produces the same failure again.
     */
    public function test_retrying_is_not_offered_when_the_camera_cannot_work_at_all(): void
    {
        $code = $this->scannerCode();

        $this->assertStringContainsString(
            "['insecure', 'unsupported']",
            $code,
            'Retrying must be suppressed for failures a retry cannot fix.'
        );

        $this->assertStringContainsString(
            "status === 'error' && canRetry",
            $this->scanner(),
            'The Try Again button must be gated on canRetry.'
        );
    }

    /**
     * Phones release the camera when the screen locks or the app is
     * backgrounded. Without a track-end handler the preview silently freezes on
     * a black frame and gate staff think the camera is broken.
     */
    public function test_a_camera_that_is_revoked_by_the_system_is_detected(): void
    {
        $body = $this->functionBody('watchStream');

        $this->assertStringContainsString("addEventListener('ended'", $body);
        $this->assertStringContainsString('fail(', $body);
    }

    /**
     * Starting and stopping must not race: a decode that arrives after teardown
     * would still look up a card.
     */
    public function test_decodes_arriving_after_teardown_are_ignored(): void
    {
        $body = $this->functionBody('start');

        $this->assertStringContainsString(
            'result && stream === acquired',
            $body,
            'The decode callback must verify the stream is still the active one.'
        );

        $this->assertMatchesRegularExpression(
            '/onBeforeUnmount\(\(\)\s*=>\s*{\s*releaseCamera\(\);/s',
            $this->scannerCode(),
            'The camera must be released when the component is destroyed.'
        );
    }
}
