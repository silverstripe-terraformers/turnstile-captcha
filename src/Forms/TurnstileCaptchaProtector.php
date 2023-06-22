<?php

namespace Terraformers\TurnstileCaptcha\Forms;

use SilverStripe\SpamProtection\SpamProtector;

class TurnstileCaptchaProtector implements SpamProtector
{

    /**
     * @param $name
     * @param $title
     * @param $value
     * @return TurnstileCaptchaField
     */
    public function getFormField($name = "Recaptcha2Field", $title = "Captcha", $value = null): TurnstileCaptchaField
    {
        return TurnstileCaptchaField::create($name, $title);
    }

    public function setFieldMapping($fieldMapping)
    {
    }
}
