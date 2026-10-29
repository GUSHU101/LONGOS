@php
  $mailBrand = site_brand_name();
  $mailBrandStyle = site_brand_style();
  $mailPrimary = $mailBrandStyle['primary_color'];
  $mailLogo = image_origin(site_brand_asset('shop_logo'));
  $mailHome = shop_route('home.index');
  $mailSupport = trim((string) system_setting('base.email', ''));
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  <title>@yield('title', $mailBrand)</title>
  <style>
    @media only screen and (max-width: 640px) {
      .mail-shell { width: 100% !important; }
      .mail-header { padding: 28px 20px 24px !important; }
      .mail-header-logo { max-width: 172px !important; max-height: 48px !important; }
      .mail-header-banner { margin-top: 20px !important; }
      .mail-header-banner-copy { padding: 15px 14px !important; }
      .mail-header-meta { display: none !important; }
      .mail-body { padding: 28px 20px !important; }
      .mail-footer { padding: 20px !important; }
      .mail-stack, .mail-stack tbody, .mail-stack tr, .mail-stack td { display: block !important; width: 100% !important; }
      .mail-stack td + td { padding-left: 0 !important; padding-top: 10px !important; }
      .mail-button { display: block !important; width: 100% !important; box-sizing: border-box !important; }
      .mail-product-image { width: 64px !important; height: 64px !important; }
      .mail-hide-mobile { display: none !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background:#f3f5f8;color:#172033;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Helvetica Neue',Arial,sans-serif;-webkit-font-smoothing:antialiased;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;">
    @yield('preheader', $mailBrand)
    &#847; &zwnj; &nbsp; &#8199; &#65279; &nbsp; &#847; &zwnj; &nbsp;
  </div>

  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;background:#f3f5f8;">
    <tr>
      <td align="center" style="padding:28px 12px 36px;">
        <table role="presentation" class="mail-shell" width="620" cellspacing="0" cellpadding="0" border="0" style="width:620px;max-width:620px;border-collapse:separate;background:#ffffff;border:1px solid #e4e7ec;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(23,32,51,.06);">
          <tr>
            <td height="6" style="height:6px;line-height:6px;font-size:0;background:{{ $mailPrimary }};">&nbsp;</td>
          </tr>
          <tr>
            <td class="mail-header" style="padding:32px 36px 28px;border-bottom:1px solid #edf0f4;background-color:#ffffff;background-image:linear-gradient(135deg,#ffffff 0%,#f8fafc 100%);">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
                <tr>
                  <td align="left" valign="middle">
                    <a href="{{ $mailHome }}" style="display:inline-block;text-decoration:none;color:#172033;">
                      <img class="mail-header-logo" src="{{ $mailLogo }}" width="190" alt="{{ $mailBrand }}" style="display:block;width:auto;max-width:190px;height:auto;max-height:54px;border:0;outline:none;text-decoration:none;">
                    </a>
                  </td>
                  <td class="mail-header-meta" align="right" valign="middle" style="font-size:12px;line-height:18px;color:#475467;white-space:nowrap;">
                    <span style="display:inline-block;padding:7px 11px;border:1px solid #e4e7ec;border-radius:999px;background:#ffffff;font-weight:700;">
                      <span style="color:{{ $mailPrimary }};font-size:16px;line-height:10px;vertical-align:-1px;">&bull;</span>
                      {{ __('mail.transactional_email') }}
                    </span>
                  </td>
                </tr>
              </table>

              <table role="presentation" class="mail-header-banner" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin-top:24px;border-collapse:separate;border:1px solid #e4e7ec;border-radius:14px;background:#f8fafc;overflow:hidden;">
                <tr>
                  <td width="6" style="width:6px;background:{{ $mailPrimary }};font-size:0;line-height:0;">&nbsp;</td>
                  <td class="mail-header-banner-copy" style="padding:17px 18px;">
                    <div style="font-size:11px;line-height:16px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#344054;">
                      @yield('mail_category', __('mail.transactional_email'))
                    </div>
                    <div style="margin-top:4px;font-size:14px;line-height:21px;color:#667085;">
                      {{ __('mail.header_caption') }}
                    </div>
                  </td>
                  <td class="mail-header-meta" width="48" align="center" valign="middle" style="width:48px;padding-right:16px;">
                    <span style="display:inline-block;width:34px;height:34px;border-radius:999px;background:#172033;color:#ffffff;text-align:center;font-size:17px;line-height:34px;font-weight:800;">&#10003;</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td class="mail-body" style="padding:36px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
                @yield('content')
              </table>
            </td>
          </tr>
          <tr>
            <td class="mail-footer" style="padding:22px 36px 26px;border-top:1px solid #edf0f4;background:#fafbfc;text-align:center;">
              <p style="margin:0 0 8px;font-size:12px;line-height:20px;color:#667085;">
                {{ __('mail.footer_sent_by', ['store' => $mailBrand]) }}
              </p>
              <p style="margin:0;font-size:12px;line-height:20px;color:#98a2b3;">
                <a href="{{ $mailHome }}" style="color:{{ $mailPrimary }};text-decoration:none;">{{ __('mail.visit_store') }}</a>
                @if ($mailSupport !== '' && filter_var($mailSupport, FILTER_VALIDATE_EMAIL))
                  <span aria-hidden="true">&nbsp;&middot;&nbsp;</span>
                  <a href="mailto:{{ $mailSupport }}" style="color:{{ $mailPrimary }};text-decoration:none;">{{ __('mail.contact_support') }}</a>
                @endif
                <br>
                &copy; {{ date('Y') }} {{ $mailBrand }}
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
