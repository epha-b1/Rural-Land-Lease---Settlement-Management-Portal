<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\Response;
use app\service\CaptchaService;

class Captcha
{
    /** GET /auth/captcha - public, returns a new challenge */
    public function generate(Request $request): Response
    {
        return json(CaptchaService::generate(), 200);
    }
}
