<?php

/**
 * WelcomeController.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-08-12 20:17:04
 * @modified   2022-08-12 20:17:04
 */

namespace Beike\Installer\Controllers;

class WelcomeController extends BaseController
{
    public function index()
    {
        $this->checkInstalled();
        $data['steps']     = 1;

        return view('installer::welcome', $data);
    }
}
