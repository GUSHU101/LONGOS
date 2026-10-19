<?php

/**
 * CurrencyController.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-28 16:21:14
 * @modified   2022-07-28 16:21:14
 */

namespace Beike\Shop\Http\Controllers;

use Beike\Services\VisitorCurrencyService;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;

class CurrencyController extends Controller
{
    public function index($currency)
    {
        // Keep the legacy URL from breaking bookmarks, but discard every old
        // manual-selection value. Currency is exclusively resolved from IP.
        Session::forget(['currency', 'currency_selection', 'currency_auto_state', 'currency.selection', 'currency.auto']);
        (new VisitorCurrencyService)->resolve(request());

        hook_action('currency.index.after', 'AUTO');

        $previousUrl = url()->previous();

        $cleanUrl = remove_url_param($previousUrl, 'price');

        return Redirect::to($cleanUrl);
    }
}
