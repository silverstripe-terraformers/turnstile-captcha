<?php

namespace Terraformers\TurnstileCaptcha\Test;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use Terraformers\TurnstileCaptcha\Forms\TurnstileCaptchaField;
use Terraformers\TurnstileCaptcha\Http\HttpClient;

class TurnstileCaptchaFieldTest extends SapphireTest
{
    protected $usesDatabase = false;

    public static function setUpBeforeClass(): void
    {
        // workaround to disable test database connection
        // @see https://github.com/silverstripe/silverstripe-framework/issues/10849
        parent::setUpBeforeClass();
        $states = static::$state->getStates();
        unset($states['fixtures']);
        static::$state->setStates($states);

        static::$tempDB = null;
    }

    /**
     * Testing the TurnstileCaptchaField Spam Protection Field
     * @return void
     */
    public function testSpamProtectionField(): void
    {
        $form = Form::create(Controller::create(), 'Form', new FieldList(), new FieldList());

        $turnstileCaptchField = new TurnstileCaptchaField('turnstileField');
        Environment::setEnv('SS_TURNSTILE_SITE_KEY', '1x00000000000000000000AA');
        Environment::setEnv('SS_TURNSTILE_SECRET_KEY', '1x0000000000000000000000000000000AA');
        $turnstileCaptchField->setForm($form);
        $this->assertNotNull($turnstileCaptchField->getSiteKey());
        $this->assertStringContainsString("Form_Form", $turnstileCaptchField->getFormID());
    }

    /**
     * Testing the local Mock API data response for the TurnstileCaptchaField
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function testTurnstileMockApi(): void
    {

        $testClient = new class extends HttpClient
        {
            public function __construct(?Client $client = null)
            {

                // Create a Mock Handler with success & fail response data
                $mock = new MockHandler([
                    // first request passes
                    new Response(200, [], '{
                        "success": true,
                        "challenge_ts": "2022-02-28T15:14:30.096Z",
                        "hostname": "example.com",
                        "error-codes": [],
                        "action": "login",
                        "cdata": "sessionid-123456789"
                        }'),
                    // second fails
                    new Response(201, [], '{
                        "success": false,
                        "error-codes": ["invalid-input-response"]
                        }'),
                    // third is an error
                    new Response(500, []),
                ]);
                $handleStack = HandlerStack::create($mock);
                $client = new Client(['handler' => $handleStack]);

                $this->client = $client;
            }
        };

        Injector::inst()->registerService($testClient, HttpClient::class);

        $request = new HTTPRequest('POST', '/', [], ['cf-turnstile-response' => 'abcd']);
        Controller::curr()->setRequest($request);

        $turnstileCaptchField = TurnstileCaptchaField::create('turnstileField');
        $result = $turnstileCaptchField->validate();
        // first request should pass validation
        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getMessages());

        // second should fail
        $result = $turnstileCaptchField->validate();
        $this->assertFalse($result->isValid());
        $errors = $result->getMessages();

        $this->assertEquals('Captcha could not be validated', $errors[0]['message']);

        // third should fail gracefully (error)
        $result = $turnstileCaptchField->validate();
        $this->assertFalse($result->isValid());
        $errors = $result->getMessages();

        $this->assertEquals('Captcha could not be validated', $errors[0]['message']);
    }
}
