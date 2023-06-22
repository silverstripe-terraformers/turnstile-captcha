<?php

namespace Terraformers\TurnstileCaptcha\Forms;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FormField;
use SilverStripe\i18n\i18n;
use SilverStripe\View\Requirements;

class TurnstileCaptchaField extends FormField
{
    /**
     * Recaptcha Site Key
     * @config NocaptchaField.site_key
     */
    private static string $site_key;

    /**
     * Recaptcha Secret Key
     * @config NocaptchaField.secret_key
     */
    private static string $secret_key;


    /**
     * CURL Proxy Server location
     * @config NocaptchaField.proxy_server
     */
    private static string $proxy_server;

    /**
     * CURL Proxy authentication
     * @config NocaptchaField.proxy_auth
     */
    private static string $proxy_auth;

    /**
     * CURL Proxy port
     * @config NocaptchaField.proxy_port
     */
    private static int|string $proxy_port;

    /**
     * Verify SSL Certificates
     * @config NocaptchaField.verify_ssl
     * @default true
     */
    private static bool $verify_ssl = true;

    /**
     * Captcha theme, currently options are light and dark
     * @default light
     */
    private static string $default_theme = 'light';


    /**
     * Whether form submit events are handled directly by this module.
     * If false, a function is provided that can be called by user code submit handlers.
     * @default true
     */
    private static bool $default_handle_submit = true;

    /**
     * Recaptcha Site Key
     * Configurable via Injector config
     */
    protected string $_siteKey;

    /**
     * Recaptcha Site Key
     * Configurable via Injector config
     */
    protected string $_secretKey;

    /**
     * CURL Proxy Server location
     * Configurable via Injector config
     */
    protected string $_proxyServer;

    /**
     * CURL Proxy authentication
     * Configurable via Injector config
     */
    protected string $_proxyAuth;

    /**
     * CURL Proxy port
     * Configurable via Injector config
     */
    protected int|string $_proxyPort;

    /**
     * Onload callback to be called for Turnstile is loaded
     *
     */
    private static $js_onload_callback = null;

    /**
     * Captcha theme, currently options are light and dark
     */
    private string $_captchaTheme;

    /**
     * The verification response
     */
    protected array $verifyResponse;


    /**
     * Whether form submit events are handled directly by this module.
     * If false, a function is provided that can be called by user code submit handlers.
     */
    private bool $handleSubmitEvents;

    /**
     * Creates a new Recaptcha 2 field.
     * @param string $name The internal field name, passed to forms.
     * @param string $title The human-readable field label.
     * @param mixed $value The value of the field (unused)
     */
    public function __construct($name, $title = null, $value = null)
    {
        parent::__construct($name, $title, $value);

        $this->title = $title;

        $this->_captchaTheme = self::config()->default_theme;
        $this->handleSubmitEvents = self::config()->default_handle_submit;
    }

    /**
     * Adds in the requirements for the field
     * @param array $properties Array of properties for the form element (not used)
     * @return string Rendered field template
     */
    public function Field($properties = array())
    {
        $siteKey = $this->getSiteKey();
        $secretKey = $this->_secretKey ? $this->_secretKey : self::config()->secret_key;

        if (empty($siteKey) || empty($secretKey)) {
            user_error('You must configure Nocaptcha.site_key and Nocaptcha.secret_key, you can retrieve these at https://google.com/recaptcha', E_USER_ERROR);
        }

        Requirements::javascript(
            'https://challenges.cloudflare.com/turnstile/v0/api.js?hl=' . Locale::getPrimaryLanguage(i18n::get_locale()) . ($this->config()->js_onload_callback ? '&onload=' . $this->config()->js_onload_callback : ''),
            [
                'async' => true,
                'defer' => true,
            ]
        );
        return parent::Field($properties);
    }


    /**
     * Validates the captcha against the Recaptcha API
     *
     * @param Validator $validator Validator to send errors to
     * @return bool Returns boolean true if valid false if not
     */
    public function validate($validator)
    {

        $request = Controller::curr()->getRequest();
        $recaptchaResponse = $request->requestVar('cf-turnstile-response');

        if (!isset($recaptchaResponse)) {
            $validator->validationError($this->name, _t(
                'Terraformers\\TurnstileCaptcha\\Forms\\CaptchaField.EMPTY',
                'if you do not see the captcha you must enable JavaScript'),
                'validation');
            return false;
        }

        if (!function_exists('curl_init')) {
            user_error('You must enable php-curl to use this field', E_USER_ERROR);
            return false;
        }

        $secret_key = $this->_secretKey ?: self::config()->secret_key;
        $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
        $ch = curl_init($url);
        $proxy_server = $this->_proxyServer ?: self::config()->proxy_server;
        if (!empty($proxy_server)) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy_server);

            $proxy_auth = $this->_proxyAuth ?: self::config()->proxy_auth;
            if (!empty($proxy_auth)) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_auth);
            }

            $proxy_port = $this->_proxyPort ?: self::config()->proxy_port;
            if (!empty($proxy_port)) {
                curl_setopt($ch, CURLOPT_PROXYPORT, $proxy_port);
            }
        }

        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, self::config()->verify_ssl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, str_replace(',', '/', 'SilverStripe'));
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            http_build_query([
                'secret' => $secret_key,
                'response' => $recaptchaResponse,
                'remoteip' => $request->getIP(),
            ])
        );
        $response = json_decode(curl_exec($ch), true);

        if (is_array($response)) {
            $this->verifyResponse = $response;

            if (!array_key_exists('success', $response) || !$response['success']) {
                $validator->validationError($this->name, _t(
                    'Terraformers\\TurnstileCaptcha\\Forms\\CaptchaField.EMPTY',
                    '_Please answer the captcha,
                     if you do not see the captcha you must enable JavaScript'),
                    'validation');
                return false;
            }

        } else {
            $validator->validationError($this->name, _t(
                'Terraformers\\TurnstileCaptcha\\Forms\\CaptchaField.VALIDATE_ERROR',
                '_Captcha could not be validated'),
                'validation');
            $logger = Injector::inst()->get(LoggerInterface::class);
            $logger->error(
                'Captcha validation failed as request was not successful.'
            );
            return false;
        }

        return true;
    }

    /**
     * Sets whether form submit events are handled directly by this module.
     *
     * @param boolean $value
     * @return TurnstileCaptchaField
     */
    public function setHandleSubmitEvents(bool $value): TurnstileCaptchaField
    {
        $this->handleSubmitEvents = $value;
        return $this;
    }

    /**
     * Get whether form submit events are handled directly by this module.
     *
     * @return boolean
     */
    public function getHandleSubmitEvents(): bool
    {
        return $this->handleSubmitEvents;
    }

    /**
     * Sets the theme for this captcha
     * @param string $value Theme to set it to, currently the api supports light and dark
     * @return TurnstileCaptchaField
     */
    public function setTheme(string $value): TurnstileCaptchaField
    {
        $this->_captchaTheme = $value;

        return $this;
    }

    /**
     * Gets the theme for this captcha
     * @return string
     */
    public function getCaptchaTheme(): string
    {
        return $this->_captchaTheme;
    }

    /**
     * Gets the site key configured via NocaptchaField.site_key this is used in the template
     * @return string
     */
    public function getSiteKey(): string
    {
        return $this->_siteKey ? $this->_siteKey : self::config()->site_key;
    }

    /**
     * Setter for _siteKey to allow injector config to override the value
     */
    public function setSiteKey($key)
    {
        $this->_siteKey = $key;
    }

    /**
     * Setter for _secretKey to allow injector config to override the value
     */
    public function setSecretKey($key)
    {
        $this->_secretKey = $key;
    }

    /**
     * Setter for _proxyServer to allow injector config to override the value
     */
    public function setProxyServer($server)
    {
        $this->_proxyServer = $server;
    }

    /**
     * Setter for _proxyAuth to allow injector config to override the value
     */
    public function setProxyAuth($auth)
    {
        $this->_proxyAuth = $auth;
    }

    /**
     * Setter for _proxyPort to allow injector config to override the value
     */
    public function setProxyPort($port)
    {
        $this->_proxyPort = $port;
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
     * @return array
     */
    public function getVerifyResponse(): array
    {
        return $this->verifyResponse;
    }

}
