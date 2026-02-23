<?php

namespace Terraformers\TurnstileCaptcha\Forms;

use GuzzleHttp\Exception\GuzzleException;
use Locale;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FormField;
use SilverStripe\i18n\i18n;
use SilverStripe\View\Requirements;
use Terraformers\TurnstileCaptcha\Http\ClientInterface;
use Terraformers\TurnstileCaptcha\Http\HttpClient;

class TurnstileCaptchaField extends FormField
{
    /**
     * Captcha theme, currently options are light and dark
     * @default light
     */
    private static string $default_theme = 'auto';

    /**
     * control the turnstile render mode
     * options are implicit or explicit
     * @defautl implicit
     */
    private static string $default_render_type = 'implicit';

    /**
     * Onload callback to be called for Turnstile is loaded
     *
     */
    private static $js_onload_callback = null;

    /**
     * Captcha theme, currently options are light and dark
     */
    private ?string $captchaTheme = null;

    /**
     * The verification response
     */
    protected array $verifyResponse = ['success' => false];

    private bool $renderType;

    private static array $dependencies = [
        'httpClient' => '%$' . HttpClient::class
    ];

    /**
     * HTTP client object
     *
     */
    public ClientInterface $httpClient;

    /**
     * Creates a new TurnstileCaptcha field.
     * @param string $name The internal field name, passed to forms.
     * @param string $title The human-readable field label.
     * @param mixed $value The value of the field (unused)
     */
    public function __construct($name, $title = null, $value = null)
    {
        parent::__construct($name, $title, $value);

        $this->title = $title;

        $this->captchaTheme = self::config()->default_theme;
        $this->setRenderType(self::config()->default_render_type);
    }

    /**
     * Adds in the requirements for the field
     * @param array $properties Array of properties for the form element (not used)
     * @return string Rendered field template
     */
    public function Field($properties = array()) //phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        $siteKey = $this->getSiteKey();
        $secretKey = $this->getSecretKey();

        if (empty($siteKey) || empty($secretKey)) {
            user_error('You must configure site_key and secret_key, you can retrieve these at https://developers.cloudflare.com/turnstile/', E_USER_ERROR);
        }


        Requirements::javascript(
            'https://challenges.cloudflare.com/turnstile/v0/api.js?language='
                . Locale::getPrimaryLanguage(i18n::get_locale())
                . ($this->config()->js_onload_callback ? '&onload=' . $this->config()->js_onload_callback : '')
                . ($this->getRenderType() === 'explicit' ? '&render=explicit' : ''),
            [
                'async' => true,
            ]
        );
        return parent::Field($properties);
    }

    /**
     * Validates the captcha against the TurnstileCaptcha API
     *
     * @throws NotFoundExceptionInterface
     * @return ValidationResult
     */
    public function validate(): ValidationResult
    {
        $this->beforeExtending('updateValidate', function (ValidationResult $result) {
            $request = Controller::curr()->getRequest();
            $captchaResponse = $request->requestVar('cf-turnstile-response');

            if (!isset($captchaResponse)) {
               $result->addFieldError(
                    $this->name,
                    _t(
                        'Terraformers\\TurnstileCaptcha\\Forms\\TurnstileCaptchaField.NOSCRIPT',
                        'if you do not see the captcha you must enable JavaScript'
                    )
                );
            }

            $client = $this->httpClient->getClient();
            try {
                $response = $client->request(
                    'POST',
                    'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                    [
                        'json' => [
                            'secret' => $this->getSecretKey(),
                            'response' => $captchaResponse,
                            'remoteip' => $request->getIP(),
                        ]
                    ]
                );

                $responseBody = json_decode($response->getBody(), true);
                if (is_array($responseBody)) {
                    $this->verifyResponse = $responseBody;
                }

            } catch (GuzzleException $e) {
                $logger = Injector::inst()->get(LoggerInterface::class);
                $logger->error($e->getMessage());
                $result->addFieldError(
                    $this->name,
                    _t(
                        'Terraformers\\TurnstileCaptcha\\Forms\\TurnstileCaptchaField.VALIDATE_ERROR',
                        'Turnstile Captcha Field could not be validated'
                    )
                );
            }

            if (
                isset($response) && $response->getStatusCode() !== 200 ||
                !$this->verifyResponse['success']
            ) {
                $result->addFieldError(
                    $this->name,
                    _t(
                        'Terraformers\\TurnstileCaptcha\\Forms\\TurnstileCaptchaField.VALIDATE_ERROR',
                        'Turnstile Captcha Field could not be validated'
                    )
                );
                $logger = Injector::inst()->get(LoggerInterface::class);
                $logger->error(
                    'Turnstile Captcha Field validation failed as request was not successful.'
                );
            }
        });

        return parent::validate();
    }

    /**
     * Sets render type of the turnstyle widget
     *
     * @param boolean $value
     * @return TurnstileCaptchaField
     */
    public function setRenderType(bool $value): TurnstileCaptchaField
    {
        $this->renderType = $value;
        return $this;
    }

    /**
     * Get the render type of the turnstyle widget
     *
     * @return boolean
     */
    public function getRenderType(): bool
    {
        return $this->renderType;
    }

    /**
     * Sets the theme for this captcha
     * @param string $value Theme to set it to, currently the api supports light, dark & auto
     * @return TurnstileCaptchaField
     */
    public function setTheme(string $value): TurnstileCaptchaField
    {
        $this->captchaTheme = $value;

        return $this;
    }

    /**
     * Gets the theme for this captcha
     * @return string
     */
    public function getCaptchaTheme(): string
    {
        return $this->captchaTheme;
    }

    /**
     * Gets the site key configured via .env variable this is used in the template
     * @return string
     */
    public function getSiteKey(): string
    {

        return Environment::getEnv('SS_TURNSTILE_SITE_KEY');
    }

    /**
     * Gets the site key configured via .env variable this is used in the template
     * @return string
     */
    public function getSecretKey(): string
    {
        return Environment::getEnv('SS_TURNSTILE_SECRET_KEY');
    }


    /**
     * Gets the form's id
     * @return ?string
     */
    public function getFormID(): ?string
    {
        return ($this->form ? $this->getTemplateHelper()->generateFormID($this->form) : null);
    }

    /**
     * get response object
     * used in tests
     * @return array
     */
    public function getVerifyResponse(): array
    {
        return $this->verifyResponse;
    }
}
