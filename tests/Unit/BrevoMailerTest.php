<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class BrevoMailerTest extends TestCase
{
    public function test_brevo_mailer_uses_api_transport()
    {
        $this->runWithEnv([
            'MAIL_DRIVER' => 'brevo',
            'BREVO_API_KEY' => 'xkeysib-test-key',
        ], function () {
            $transport = Mail::mailer('brevo')->getSymfonyTransport();

            $this->assertInstanceOf(BrevoApiTransport::class, $transport);
            $this->assertSame('brevo+api://api.brevo.com', (string) $transport);
        });
    }

    public function test_brevo_mailer_requires_api_key()
    {
        $this->runWithEnv([
            'MAIL_DRIVER' => 'brevo',
            'BREVO_API_KEY' => null,
        ], function () {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('BREVO_API_KEY must be set when MAIL_DRIVER=brevo.');

            Mail::mailer('brevo')->getSymfonyTransport();
        });
    }

    public function test_brevo_api_transport_sends_via_http_api()
    {
        $capturedRequest = null;
        $client = new MockHttpClient(function ($method, $url, $options) use (&$capturedRequest) {
            $capturedRequest = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            return new MockResponse(json_encode(['messageId' => '<test-message-id@brevo>']), [
                'http_code' => 201,
            ]);
        });

        $transport = (new BrevoTransportFactory(null, $client))->create(
            new Dsn('brevo+api', 'default', 'xkeysib-test-key')
        );

        $email = (new Email())
            ->from('bookstack@example.com')
            ->to('recipient@example.com')
            ->subject('Brevo API test')
            ->text('Hello from BookStack');

        $transport->send($email);

        $this->assertNotNull($capturedRequest);
        $this->assertSame('POST', $capturedRequest['method']);
        $this->assertSame('https://api.brevo.com/v3/smtp/email', $capturedRequest['url']);

        $apiKeyHeader = $capturedRequest['options']['normalized_headers']['api-key'][0] ?? '';
        $this->assertSame('api-key: xkeysib-test-key', $apiKeyHeader);

        $payload = json_decode($capturedRequest['options']['body'], true);
        $this->assertSame('bookstack@example.com', $payload['sender']['email']);
        $this->assertSame('recipient@example.com', $payload['to'][0]['email']);
        $this->assertSame('Brevo API test', $payload['subject']);
        $this->assertSame('Hello from BookStack', $payload['textContent']);
    }

    public function test_brevo_api_transport_surfaces_api_errors()
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['message' => 'Key not found']), [
                'http_code' => 401,
            ]),
        ]);

        $transport = (new BrevoTransportFactory(null, $client))->create(
            new Dsn('brevo+api', 'default', 'bad-key')
        );

        $email = (new Email())
            ->from('bookstack@example.com')
            ->to('recipient@example.com')
            ->subject('Brevo API test')
            ->text('Hello from BookStack');

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Key not found');

        $transport->send($email);
    }
}
