Turnstile Smart CAPTCHA
=================

Adds a "spam protection" field to SilverStripe userforms using Cloudflare's
[smart CAPTCHA](https://developers.cloudflare.com/turnstile) service.

## Requirements
* SilverStripe 5.x
* [SilverStripe Spam Protection
  3.x](https://github.com/silverstripe/silverstripe-spamprotection/)
* PHP CURL

## Installation
```
composer require silverstripe-terraformers/turnstileCaptcha
```

After installing the module via composer or manual install you must set the spam
protector to NocaptchaProtector, this needs to be set in your site's config file
normally this is mysite/\_config/config.yml.
```yml
SilverStripe\SpamProtection\Extension\FormSpamProtectionExtension:
    default_spam_protector: Terraformers\TurnstileCaptcha\Forms\TurnstileCaptchaProtector
```

Finally, add the "spam protection" field to your form by calling
``enableSpamProtection()`` on the form object.
```php
$form->enableSpamProtection();
```

## Configuration
There are multiple configuration options for the field, you must set the
site_key and the secret_key which you can get from the [reCAPTCHA
page](https://www.google.com/recaptcha). These configuration options must be
added to your site's yaml config typically this is mysite/\_config/config.yml.
```yml
Terraformers\TurnstileCaptcha\Forms\TurnstileCaptchaField:
    site_key: "YOUR_SITE_KEY" #Your site key (required)
    secret_key: "YOUR_SECRET_KEY" #Your secret key (required)
    verify_ssl: true #Allows you to disable php-curl's SSL peer verification by setting this to false (optional, defaults to true)
    default_theme: "light" #Default theme color (optional, light or dark, defaults to light)
    default_handle_submit: true #Default setting for whether nocaptcha should handle form submission. See "Handling form submission" below.
    proxy_server: "" #Your proxy server address (optional)
    proxy_port: "" #Your proxy server address port (optional)
    proxy_auth: "" #Your proxy server authentication information (optional)
```

## Adding field labels

If you want to add a field label or help text to the Captcha field you can do so
like this:

```php
$form->enableSpamProtection()
    ->fields()->fieldByName('Captcha')
    ->setTitle("Spam protection")
    ->setDescription("Please tick the box to prove you're a human and help us stop spam.");
```

### Commenting Module
When your using the
[silverstripe/comments](https://github.com/silverstripe/silverstripe-comments)
module you must add the following (per their documentation) to your \_config.php
in order to use Terraformers\TurnstileCaptcha on comment forms.

```php
CommentingController::add_extension('CommentSpamProtection');
```

## Retrieving the Verify Response

If you wish to manually retrieve the Site Verify response in you form action use
the `getVerifyResponse()` method

```php
function doSubmit($data, $form) {
    $captchaResponse = $form->Fields()->fieldByName('Captcha')->getVerifyResponse();

    // $captchaResponse = array (size=5) [
    //  'success' => boolean true
    //  'challenge_ts' => string '2020-09-08T20:48:34Z' (length=20)
    //  'hostname' => string 'localhost' (length=9)
    //  'score' => float 0.9
    //  'action' => string 'submit' (length=6)
    // ];
}
```

## Handling form submission
By default, the javascript included with this module will add a submit event handler to your form.

If you need to handle form submissions in a special way (for example to support front-end validation),
you can choose to handle form submit events yourself.

This can be configured site-wide using the Config API
```yml
Terraformers\TurnstileCaptcha\Forms\TurnstileCaptchaField:
    default_handle_submit: false
```

Or on a per form basis:
```php
$captchaField = $form->Fields()->fieldByName('Captcha');
$captchaField->setHandleSubmitEvents(false);
```

With this configuration no event handlers will be added by this module to your form. Instead, a
function will be provided called `nocaptcha_handleCaptcha` which you can call from your code
when you're ready to submit your form. It has the following signature:
```js
function nocaptcha_handleCaptcha(form, callback)
```
`form` must be the form element, and `callback` should be a function that finally submits the form,
though it is optional.

In the simplest case, you can use it like this:
```js
document.addEventListener("DOMContentLoaded", function(event) {
    // where formID is the element ID for your form
    const form = document.getElementById(formID);
    const submitListener = function(event) {
        event.preventDefault();
        let valid = true;
        /* Your validation logic here */
        if (valid) {
            nocaptcha_handleCaptcha(form, form.submit.bind(form));
        }
    };
    form.addEventListener('submit', submitListener);
});
```

## Reporting an issue

When you're reporting an issue please ensure you specify what version of
SilverStripe you are using i.e. 3.1.3, 3.2beta, master etc. Also be sure to
include any JavaScript or PHP errors you receive, for PHP errors please ensure
you include the full stack trace. Also please include how you produced the
issue. You may also be asked to provide some of the classes to aid in
re-producing the issue. Stick with the issue, remember that you seen the issue
not the maintainer of the module so it may take allot of questions to arrive at
a fix or answer.
