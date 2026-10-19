<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="app-timezone" content="{{ config('app.timezone', 'UTC') }}">
  <title>LONGOS 店铺编辑器</title>
  <script defer src="{{ mix('build/beike/admin/js/puck-builder.js') }}"></script>
  <style>
    html, body, #beike-puck-builder { height: 100%; min-height: 100%; margin: 0; }
    :root {
      --beike-ink: #000000; --beike-cream: #fbfbf5; --beike-aloe: #c1fbd4; --beike-pistachio: #d4f9e0;
      --beike-hairline: #e4e4e7; --beike-muted: #52525b; --beike-focus: #006d3b;
    }
    body { background: var(--beike-cream); color: var(--beike-ink); font-family: Inter, ui-sans-serif, system-ui, sans-serif; font-feature-settings: "ss03"; }
    .beike-puck-conflict-dialog { box-sizing: border-box; width: min(520px, calc(100vw - 32px)); border: 0; border-radius: 14px; padding: 24px; color: var(--beike-ink); background: #fff; box-shadow: 0 25px 55px rgba(16,24,40,.28); }
    .beike-puck-conflict-dialog::backdrop { background: rgba(16,24,40,.62); }
    .beike-puck-conflict-dialog__icon { display: grid; place-items: center; width: 44px; height: 44px; margin-bottom: 14px; border-radius: 50%; color: #b42318; background: #fee4e2; font-size: 24px; font-weight: 800; }
    .beike-puck-conflict-dialog h2 { margin: 0; font-size: 19px; }
    .beike-puck-conflict-dialog p { margin: 9px 0 20px; color: var(--beike-muted); font-size: 13px; line-height: 1.6; }
    .beike-puck-conflict-dialog__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
    .beike-puck-conflict-dialog button { min-height: 44px; border: 1px solid var(--beike-hairline); border-radius: 999px; padding: 8px 14px; color: var(--beike-ink); background: #fff; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; }
    .beike-puck-conflict-dialog button.is-danger { border-color: #b42318; color: #fff; background: #b42318; }
    .beike-puck-conflict-dialog button:disabled { cursor: wait; opacity: .62; }
    .beike-puck-conflict-dialog button:focus-visible { outline: 3px solid var(--beike-focus); outline-offset: 2px; }
    .beike-puck-existing-insert input, .beike-puck-existing-insert__group button, .beike-puck-revision-dialog__row button, .beike-puck-header-controls select, .beike-puck-header-action, .beike-puck-header-more > summary, .beike-puck-header-more > div > a, .beike-puck-header-more > div > button, .beike-puck-structure__item-actions button, .beike-puck-structure__back, .beike-puck-preview-target input, .beike-puck-preview-target__selected, .beike-puck-preview-target__results button, .beike-puck-device-controls button { min-height: 44px !important; }
    #beike-puck-builder {
      --puck-color-surface: #ffffff; --puck-color-surface-muted: var(--beike-cream); --puck-color-surface-subtle: #ffffff;
      --puck-color-surface-inverse: var(--beike-ink); --puck-color-border: var(--beike-hairline); --puck-color-border-muted: #f1f1f1;
      --puck-color-border-hover: #52525b; --puck-color-text: var(--beike-ink); --puck-color-text-secondary: #3f3f46;
      --puck-color-text-muted: var(--beike-muted); --puck-color-interactive: #2563eb; --puck-color-interactive-hover: #1d4ed8;
      --puck-color-interactive-active: #1e40af; --puck-color-interactive-subtle: #eff6ff; --puck-color-interactive-soft: #f8fbff;
      --puck-color-interactive-soft-hover: #eff6ff; --puck-color-focus-ring: #2563eb; --puck-color-selection-bg: #dbeafe;
      --puck-color-selection-border: #2563eb; --puck-color-line-placeholder: #2563eb; --puck-radius-xs: 4px;
      --puck-radius-s: 5px; --puck-radius-m: 8px; --puck-radius-l: 12px; --puck-radius-pill: 9999px; --puck-space-chrome-gutter: 16px;
      --puck-font-family: Inter, ui-sans-serif, system-ui, sans-serif; --puck-header-color-bg: #ffffff; --puck-header-color-text: var(--beike-ink);
    }
    .beike-puck-section { box-sizing: border-box; max-width: 1160px; margin: 24px auto; padding: clamp(28px, 5vw, 72px); border: 1px solid #dfe3eb; border-radius: 12px; background: #fff; }
    .beike-puck-section__eyebrow { margin: 0 0 10px; color: var(--beike-muted); font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
    .beike-puck-section h2 { margin: 0 0 12px; font-size: clamp(24px, 4vw, 48px); letter-spacing: -.04em; }
    .beike-puck-section p { max-width: 62ch; color: #475467; line-height: 1.6; }
    .beike-puck-section--unknown { border-color: #fecdca; background: #fffbfa; }.beike-puck-section--unknown h2 { color: #b42318; }
    .beike-puck-section__action { display: inline-flex; align-items: center; min-height: 44px; margin-top: 12px; border: 1px solid var(--beike-ink); border-radius: 9999px; padding: 10px 18px; color: #fff; background: var(--beike-ink); font-size: 14px; font-weight: 700; }
    .beike-puck-section__hero { display: grid; grid-template-columns: minmax(0, 1fr) minmax(220px, .8fr); align-items: center; gap: clamp(20px, 4vw, 56px); }.beike-puck-section__image, .beike-puck-section__image-placeholder { display: block; box-sizing: border-box; width: 100%; min-height: 220px; border-radius: 10px; object-fit: cover; }.beike-puck-section__image-placeholder { display: grid; place-items: center; border: 1px dashed #98a2b3; color: #667085; background: #f9fafb; font-size: 13px; }.beike-puck-video-preview { aspect-ratio: 16 / 9; margin-top: 24px; overflow: hidden; border-radius: 10px; background: #101828; }.beike-puck-video-preview video, .beike-puck-video-preview iframe { display: block; width: 100%; height: 100%; border: 0; }.beike-puck-video-preview .beike-puck-section__image-placeholder { height: 100%; color: #98a2b3; background: #101828; }
    .beike-puck-section__cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin: 24px 0; }.beike-puck-section__cards article { display: grid; gap: 6px; padding: 16px; border: 1px solid #dfe3eb; border-radius: 9px; text-align: left; }.beike-puck-section__cards span { color: #667085; font-size: 13px; line-height: 1.5; }.beike-puck-section__multicolumn article { padding: 0 0 16px; overflow: hidden; }.beike-puck-section__multicolumn article strong, .beike-puck-section__multicolumn article span { padding-inline: 16px; }.beike-puck-section__multicolumn article strong { padding-top: 10px; }.beike-puck-section__multicolumn article img { width: 100%; aspect-ratio: 1; object-fit: cover; }.beike-puck-section__faq { display: grid; gap: 8px; margin: 24px 0; text-align: left; }.beike-puck-section__faq details { border: 1px solid #dfe3eb; border-radius: 8px; padding: 12px 14px; }.beike-puck-section__faq summary { cursor: pointer; font-weight: 700; }.beike-puck-section__faq p { margin: 10px 0 0; }
    .beike-puck-revision-dialog { width: min(560px, calc(100vw - 32px)); padding: 0; border: 0; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,.25); }.beike-puck-revision-dialog::backdrop { background: rgba(0,0,0,.58); }.beike-puck-revision-dialog__head { display: flex; align-items: start; justify-content: space-between; gap: 16px; padding: 18px; border-bottom: 1px solid var(--beike-hairline); }.beike-puck-revision-dialog__head h2 { margin: 0; font-size: 16px; }.beike-puck-revision-dialog__head p { margin: 5px 0 0; color: var(--beike-muted); font-size: 12px; }.beike-puck-revision-dialog__head button { border: 0; background: transparent; font-size: 20px; cursor: pointer; }.beike-puck-revision-dialog__body { display: grid; gap: 8px; max-height: 52vh; overflow: auto; padding: 14px 18px; }.beike-puck-revision-dialog__row { display: flex; align-items: center; justify-content: space-between; gap: 12px; border: 1px solid var(--beike-hairline); border-radius: 8px; padding: 10px; }.beike-puck-revision-dialog__row.is-confirming { border-color: #fda29b; background: #fff8f7; }.beike-puck-revision-dialog__row strong, .beike-puck-revision-dialog__row small { display: block; }.beike-puck-revision-dialog__row small { margin-top: 3px; color: var(--beike-muted); font-size: 12px; }.beike-puck-revision-dialog__row button { min-height: 40px; border: 1px solid var(--beike-ink); border-radius: 999px; padding: 7px 12px; color: var(--beike-ink); background: #fff; font: inherit; font-size: 12px; cursor: pointer; }.beike-puck-revision-dialog__confirm { display: flex; gap: 6px; }.beike-puck-revision-dialog__confirm button.is-danger { border-color: #d92d20; color: #fff; background: #d92d20; }.beike-puck-revision-dialog__row button:focus-visible { outline: 3px solid var(--beike-focus); outline-offset: 2px; }.beike-puck-revision-dialog__empty { color: var(--beike-muted); font-size: 13px; }
    .beike-puck-existing-insert { display: grid; gap: 14px; padding: 14px; }.beike-puck-existing-insert header { display: grid; gap: 5px; }.beike-puck-existing-insert header strong { font-size: 14px; }.beike-puck-existing-insert header p, .beike-puck-existing-insert__empty { margin: 0; color: var(--beike-muted); font-size: 12px; line-height: 1.5; }.beike-puck-existing-insert > label { display: grid; gap: 6px; color: var(--beike-ink); font-size: 12px; font-weight: 700; }.beike-puck-existing-insert input { box-sizing: border-box; width: 100%; min-height: 40px; border: 1px solid #98a2b3; border-radius: 7px; padding: 8px 9px; color: var(--beike-ink); background: #fff; font: inherit; font-size: 12px; }.beike-puck-existing-insert input:focus-visible, .beike-puck-existing-insert button:focus-visible { outline: 3px solid var(--beike-focus); outline-offset: 2px; }.beike-puck-existing-insert__group { display: grid; gap: 6px; }.beike-puck-existing-insert__group h2 { margin: 0; color: var(--beike-muted); font-size: 11px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }.beike-puck-existing-insert__group > div { display: grid; border-top: 1px solid var(--beike-hairline); }.beike-puck-existing-insert__group button { display: flex; min-height: 42px; align-items: center; justify-content: space-between; gap: 9px; border: 0; border-bottom: 1px solid var(--beike-hairline); padding: 8px 2px; color: var(--beike-ink); background: transparent; font: inherit; font-size: 13px; font-weight: 700; text-align: left; cursor: pointer; }.beike-puck-existing-insert__group button:hover { color: var(--beike-focus); background: var(--beike-pistachio); }.beike-puck-existing-insert__group button > span { display: grid; min-width: 0; gap: 2px; }.beike-puck-existing-insert__group button strong, .beike-puck-existing-insert__group button small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.beike-puck-existing-insert__group button small { color: var(--beike-muted); font-size: 11px; font-weight: 500; }.beike-puck-existing-insert__group button em { flex: 0 0 auto; border: 1px solid var(--beike-hairline); border-radius: 999px; padding: 5px 8px; color: var(--beike-ink); font-size: 11px; font-style: normal; }
    .beike-puck-media-field { display: grid; gap: 8px; }.beike-puck-media-field__preview { display: grid; place-items: center; min-height: 92px; overflow: hidden; border: 1px solid #d0d5dd; border-radius: 8px; color: #667085; background: #f9fafb; font-size: 12px; }.beike-puck-media-field__preview img, .beike-puck-media-field__preview video { display: block; width: 100%; max-height: 180px; object-fit: cover; }.beike-puck-video-field .beike-puck-media-field__preview { min-height: 132px; background: #101828; }.beike-puck-media-field__actions { display: flex; gap: 8px; }.beike-puck-media-field__error { margin: 0 16px; padding: 9px 11px; border-radius: 7px; color: #912018; background: #fee4e2; font-size: 12px; }.beike-puck-media-field button { appearance: none; border: 1px solid #98a2b3; border-radius: 6px; padding: 7px 9px; color: #344054; background: #fff; cursor: pointer; font: inherit; font-size: 12px; }.beike-puck-media-field button:disabled { cursor: not-allowed; opacity: .55; }.beike-puck-media-dialog { width: min(1080px, calc(100vw - 32px)); max-width: 1080px; height: min(720px, calc(100vh - 32px)); padding: 0; border: 0; border-radius: 12px; box-shadow: 0 20px 70px rgba(16,24,40,.28); }.beike-puck-media-dialog::backdrop { background: rgba(16,24,40,.48); }.beike-puck-media-dialog__head { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 16px; border-bottom: 1px solid #eaecf0; }.beike-puck-media-dialog__head strong { font-size: 14px; }.beike-puck-media-dialog__head button { border: 0; color: #475467; background: transparent; font-size: 20px; cursor: pointer; }.beike-puck-media-dialog iframe { display: block; width: 100%; height: calc(100% - 54px); border: 0; }
    .beike-puck-external-video-field { display: grid; gap: 6px; }.beike-puck-external-video-field label { color: #344054; font-size: 12px; font-weight: 700; }.beike-puck-external-video-field input { box-sizing: border-box; width: 100%; border: 1px solid #98a2b3; border-radius: 6px; padding: 8px 9px; color: #101828; background: #fff; font: inherit; font-size: 12px; }.beike-puck-external-video-field input[aria-invalid="true"] { border-color: #d92d20; }.beike-puck-external-video-field small { color: #667085; font-size: 11px; line-height: 1.4; }.beike-puck-external-video-field small[role="alert"] { color: #b42318; }.beike-puck-external-video-field a { width: fit-content; color: #175cd3; font-size: 12px; font-weight: 700; }
    .beike-puck-link-field { display: grid; gap: 6px; }.beike-puck-link-field label { color: #344054; font-size: 12px; font-weight: 700; }.beike-puck-link-field input { box-sizing: border-box; width: 100%; border: 1px solid #98a2b3; border-radius: 6px; padding: 8px 9px; color: #101828; background: #fff; font: inherit; font-size: 12px; }.beike-puck-link-field input[aria-invalid="true"] { border-color: #d92d20; }.beike-puck-link-field small { color: #667085; font-size: 11px; line-height: 1.4; }.beike-puck-link-field small[role="alert"] { color: #b42318; }.beike-puck-link-field a { width: fit-content; color: #175cd3; font-size: 12px; font-weight: 700; }
    .beike-puck-structured-link { display: grid; grid-template-columns: minmax(0, .8fr) minmax(0, 1.2fr); gap: 8px; }.beike-puck-structured-link > div { display: grid; gap: 5px; }.beike-puck-structured-link__picker { grid-column: 1 / -1; }.beike-puck-structured-link label { color: #344054; font-size: 11px; font-weight: 700; }.beike-puck-structured-link select, .beike-puck-structured-link input:not([type="checkbox"]) { box-sizing: border-box; width: 100%; min-height: 38px; border: 1px solid #98a2b3; border-radius: 7px; padding: 7px 8px; color: #101828; background: #fff; font: inherit; font-size: 12px; }.beike-puck-structured-link__window { grid-column: 1 / -1; display: flex; align-items: center; gap: 7px; margin-top: 1px; }.beike-puck-structured-link__window input { width: 16px; height: 16px; }
    .beike-puck-advanced-json { display: grid; gap: 7px; }.beike-puck-advanced-json textarea { box-sizing: border-box; width: 100%; min-height: 190px; border: 1px solid #98a2b3; border-radius: 8px; padding: 10px; color: #101828; background: #fff; font: 12px/1.55 ui-monospace, SFMono-Regular, Consolas, monospace; resize: vertical; }.beike-puck-advanced-json textarea:focus-visible { outline: 3px solid var(--beike-focus); outline-offset: 2px; }.beike-puck-advanced-json small { color: #667085; font-size: 11px; line-height: 1.45; }.beike-puck-advanced-json small[role="alert"] { color: #b42318; font-weight: 700; }
    .beike-puck-commerce-field { display: grid; gap: 8px; }.beike-puck-commerce-field input { box-sizing: border-box; width: 100%; border: 1px solid #98a2b3; border-radius: 6px; padding: 8px 9px; color: #101828; background: #fff; font: inherit; font-size: 12px; }.beike-puck-commerce-field__selected, .beike-puck-commerce-field__results { display: grid; gap: 5px; }.beike-puck-commerce-field__item { display: flex; align-items: center; gap: 8px; width: 100%; border: 1px solid #eaecf0; border-radius: 7px; padding: 6px; color: #344054; background: #fff; text-align: left; }.beike-puck-commerce-field__item img { width: 32px; height: 32px; border-radius: 5px; object-fit: cover; background: #f2f4f7; }.beike-puck-commerce-field__item span { display: grid; gap: 1px; min-width: 0; }.beike-puck-commerce-field__item strong, .beike-puck-commerce-field__item small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.beike-puck-commerce-field__item small { color: #667085; font-size: 11px; }.beike-puck-commerce-field__selected button { display: flex; align-items: center; justify-content: space-between; }.beike-puck-commerce-field__empty { color: #667085; font-size: 12px; }
    .beike-puck-payment-methods { display: grid; gap: 8px; margin: 0; border: 0; padding: 0; }.beike-puck-payment-methods legend { padding: 0; color: #475467; font-size: 12px; line-height: 1.45; }.beike-puck-payment-methods > div { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; }.beike-puck-payment-methods label { display: flex; min-height: 36px; align-items: center; gap: 7px; border: 1px solid #d0d5dd; border-radius: 6px; padding: 6px 8px; color: #344054; background: #fff; font-size: 12px; cursor: pointer; }.beike-puck-payment-methods input { width: 16px; height: 16px; accent-color: var(--beike-focus); }.beike-puck-payment-methods label:has(input:focus-visible) { outline: 3px solid var(--beike-focus); outline-offset: 2px; }.beike-puck-payment-methods label:has(input:disabled) { cursor: not-allowed; opacity: .6; }
    .beike-puck-color-field { display: flex; align-items: center; gap: 8px; }.beike-puck-color-field input { width: 34px; height: 30px; padding: 2px; border: 1px solid #98a2b3; border-radius: 6px; background: #fff; cursor: pointer; }.beike-puck-color-field output { flex: 1; min-height: 30px; border: 1px solid #eaecf0; border-radius: 6px; padding: 7px 8px; color: #475467; background: #fff; font-size: 12px; }.beike-puck-gradient-field output { color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,.45); }
    .beike-puck-range-field { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 8px; }.beike-puck-range-field > input { accent-color: var(--beike-focus); width: 100%; }.beike-puck-range-field label { display: flex; align-items: center; gap: 5px; }.beike-puck-range-field input[type="number"] { width: 66px; min-height: 32px; border: 1px solid #98a2b3; border-radius: 6px; padding: 5px 6px; font: inherit; font-size: 12px; }.beike-puck-range-field output { min-width: 12px; color: #667085; font-size: 12px; }.beike-puck-color-scheme { box-sizing: border-box; width: 100%; min-height: 34px; border: 1px solid #98a2b3; border-radius: 6px; padding: 6px 8px; color: #344054; background: #fff; font: inherit; font-size: 12px; }
    .beike-puck-media-field button, .beike-puck-commerce-field__item, .beike-puck-structured-link select, .beike-puck-structured-link input:not([type="checkbox"]), .beike-puck-range-field input[type="number"], .beike-puck-color-field input { min-height: 44px; }.beike-puck-color-field input { min-width: 44px; }.beike-puck-media-field button:focus-visible, .beike-puck-commerce-field__item:focus-visible, .beike-puck-structured-link input:focus-visible, .beike-puck-structured-link select:focus-visible, .beike-puck-color-field input:focus-visible, .beike-puck-range-field input:focus-visible { outline: 3px solid var(--beike-focus); outline-offset: 2px; }.beike-puck-commerce-field__status, .beike-puck-commerce-field__hint { color: var(--beike-muted); font-size: 12px; line-height: 1.45; }.beike-puck-commerce-field__status { font-weight: 700; }.beike-puck-commerce-field__item { cursor: pointer; }.beike-puck-commerce-field__item:disabled { cursor: not-allowed; opacity: .58; }.beike-puck-commerce-field__results { max-height: 242px; overflow: auto; padding-right: 2px; }.beike-puck-existing-insert__group button, .beike-puck-structure__items button, .beike-puck-structure__chrome button { min-height: 44px; }.beike-puck-structured-link__window { min-height: 44px; }.beike-puck-structured-link__window input { min-width: 20px; min-height: 20px; }
    .beike-puck-failure { max-width: 620px; margin: 12vh auto; border: 1px solid #fecdca; border-radius: 12px; padding: 32px; color: #7a271a; background: #fffbfa; }.beike-puck-failure h1 { margin-top: 0; color: #b42318; }.beike-puck-failure a { display: inline-block; margin-top: 12px; border-radius: 8px; padding: 10px 14px; color: #fff; background: #155eef; text-decoration: none; }
    .beike-puck-header-controls { display: flex; align-items: center; gap: 8px; min-width: 0; margin-left: auto; padding-right: 8px; }.beike-puck-header-controls > label { display: flex; align-items: center; gap: 6px; color: var(--beike-ink); font-size: 12px; font-weight: 700; white-space: nowrap; }.beike-puck-header-controls select { min-height: 34px; border: 1px solid var(--beike-hairline); border-radius: 6px; padding: 5px 7px; color: var(--beike-ink); background: #fff; font: inherit; font-size: 12px; }.beike-puck-header-action { display: inline-flex; align-items: center; min-height: 34px; border: 1px solid var(--beike-hairline); border-radius: 6px; padding: 6px 10px; color: var(--beike-ink); background: #fff; font: inherit; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; white-space: nowrap; }.beike-puck-header-action:hover { border-color: #98a2b3; background: #f8fafc; }.beike-puck-header-action.is-primary { border-color: var(--beike-ink); background: var(--beike-aloe); }.beike-puck-header-action:disabled { cursor: wait; opacity: .72; }.beike-puck-header-more { position: relative; }.beike-puck-header-more > summary { display: inline-flex; min-height: 34px; align-items: center; border: 1px solid var(--beike-hairline); border-radius: 6px; padding: 6px 10px; color: var(--beike-ink); background: #fff; font-size: 12px; font-weight: 700; cursor: pointer; list-style: none; }.beike-puck-header-more > summary::-webkit-details-marker { display: none; }.beike-puck-header-more > summary::after { content: "⌄"; margin-left: 6px; }.beike-puck-header-more[open] > summary { border-color: #98a2b3; background: #f8fafc; }.beike-puck-header-more > div { position: absolute; z-index: 40; top: calc(100% + 6px); right: 0; display: grid; min-width: 178px; overflow: hidden; border: 1px solid var(--beike-hairline); border-radius: 8px; padding: 5px; background: #fff; box-shadow: 0 12px 28px rgba(16,24,40,.18); }.beike-puck-header-more > div > a, .beike-puck-header-more > div > button { display: flex; width: 100%; min-height: 38px; align-items: center; border: 0; border-radius: 5px; padding: 8px; color: var(--beike-ink); background: #fff; font: inherit; font-size: 12px; font-weight: 600; text-align: left; text-decoration: none; cursor: pointer; }.beike-puck-header-more > div > a:hover, .beike-puck-header-more > div > button:hover { background: var(--beike-pistachio); }.beike-puck-header-action:focus-visible, .beike-puck-header-controls select:focus-visible, .beike-puck-header-more > summary:focus-visible, .beike-puck-header-more > div > a:focus-visible, .beike-puck-header-more > div > button:focus-visible, .beike-puck-chrome-dialog button:focus-visible, .beike-puck-preview-target button:focus-visible, .beike-puck-preview-target input:focus-visible { outline: 3px solid var(--beike-focus); outline-offset: 2px; }
    .beike-puck-chrome-dialog { width: min(920px, calc(100vw - 32px)); max-width: 920px; max-height: calc(100vh - 32px); padding: 0; border: 0; border-radius: 14px; box-shadow: 0 25px 50px -12px rgba(0,0,0,.25); color: var(--beike-ink); background: #fff; }.beike-puck-chrome-dialog::backdrop { background: rgba(0,0,0,.58); }.beike-puck-chrome-dialog form { display: grid; max-height: calc(100vh - 32px); }.beike-puck-chrome-dialog__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 20px 22px 15px; border-bottom: 1px solid var(--beike-hairline); }.beike-puck-chrome-dialog h2 { margin: 0; font-size: 18px; }.beike-puck-chrome-dialog h3 { margin: 0 0 12px; font-size: 15px; }.beike-puck-chrome-dialog p { margin: 5px 0 0; color: var(--beike-muted); font-size: 13px; line-height: 1.5; }.beike-puck-chrome-dialog__head > button { min-width: 44px; min-height: 44px; border: 0; padding: 0 4px; color: var(--beike-muted); background: transparent; font-size: 24px; cursor: pointer; }.beike-puck-chrome-tabs { display: flex; gap: 8px; padding: 12px 22px; border-bottom: 1px solid var(--beike-hairline); }.beike-puck-chrome-tabs button { min-height: 44px; border: 1px solid var(--beike-hairline); border-radius: 9999px; padding: 8px 14px; color: var(--beike-ink); background: #fff; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }.beike-puck-chrome-tabs button.is-active { border-color: var(--beike-ink); color: var(--beike-ink); background: var(--beike-aloe); }.beike-puck-chrome-dialog__body { display: grid; gap: 16px; max-height: min(62vh, 680px); overflow: auto; padding: 18px 22px; }.beike-puck-chrome-section { display: grid; gap: 10px; border: 1px solid var(--beike-hairline); border-radius: 10px; padding: 15px; }.beike-puck-chrome-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }.beike-puck-chrome-dialog label { display: grid; gap: 6px; color: var(--beike-ink); font-size: 12px; font-weight: 700; }.beike-puck-chrome-dialog input:not([type="checkbox"]), .beike-puck-chrome-dialog textarea { box-sizing: border-box; width: 100%; border: 1px solid #767676; border-radius: 8px; padding: 10px 11px; color: var(--beike-ink); background: #fff; font: inherit; font-size: 13px; }.beike-puck-chrome-dialog input[type="color"] { min-height: 44px; padding: 3px; }.beike-puck-chrome-dialog textarea { min-height: 76px; resize: vertical; }.beike-puck-chrome-switch { display: flex !important; align-items: center; gap: 8px !important; }.beike-puck-chrome-switch input { width: 17px; height: 17px; }.beike-puck-chrome-card { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto; align-items: end; gap: 10px; border: 1px solid var(--beike-hairline); border-radius: 8px; padding: 10px; }.beike-puck-chrome-dialog button { width: fit-content; min-height: 44px; border: 1px solid var(--beike-ink); border-radius: 9999px; padding: 8px 14px; color: var(--beike-ink); background: #fff; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; }.beike-puck-chrome-dialog button.is-danger { border-color: #b42318; color: #b42318; }.beike-puck-chrome-dialog__foot { display: flex; justify-content: flex-end; gap: 9px; padding: 15px 22px; border-top: 1px solid var(--beike-hairline); }.beike-puck-chrome-dialog__foot .is-primary { border-color: var(--beike-ink); color: var(--beike-ink); background: var(--beike-aloe); }.beike-puck-chrome-panel { width: auto; max-width: none; max-height: none; border: 0; border-radius: 0; box-shadow: none; background: transparent; }.beike-puck-chrome-panel form { max-height: none; }.beike-puck-chrome-panel .beike-puck-chrome-dialog__head { padding: 14px; }.beike-puck-chrome-panel .beike-puck-chrome-tabs { padding: 10px 14px; }.beike-puck-chrome-panel .beike-puck-chrome-dialog__body { max-height: calc(100vh - 260px); padding: 14px; }.beike-puck-chrome-panel .beike-puck-chrome-dialog__foot { padding: 12px 14px; }.beike-puck-structure { display: grid; gap: 12px; padding: 12px; }.beike-puck-structure > header strong { display: block; color: var(--beike-ink); font-size: 14px; }.beike-puck-structure > header p, .beike-puck-structure__empty { margin: 5px 0 0; color: var(--beike-muted); font-size: 12px; line-height: 1.5; }.beike-puck-structure__items, .beike-puck-structure__chrome { display: grid; gap: 6px; }.beike-puck-structure__item { display: grid; gap: 6px; border: 1px solid var(--beike-hairline); border-radius: 7px; padding: 6px; background: #fff; }.beike-puck-structure__item.is-dragging { opacity: .56; }.beike-puck-structure__item.is-drop-target { border-color: var(--beike-focus); box-shadow: 0 0 0 2px color-mix(in srgb, var(--beike-focus) 20%, transparent); }.beike-puck-structure__item-select, .beike-puck-structure__chrome button { display: grid; grid-template-columns: auto minmax(0, 1fr); align-items: center; column-gap: 8px; min-height: 46px; border: 0; border-radius: 5px; padding: 6px; color: var(--beike-ink); background: #fff; font: inherit; text-align: left; cursor: pointer; }.beike-puck-structure__item-select:hover, .beike-puck-structure__chrome button:hover { background: #f8fafc; }.beike-puck-structure__item-select > span { grid-row: span 2; color: var(--beike-muted); cursor: grab; }.beike-puck-structure__item-select:active > span { cursor: grabbing; }.beike-puck-structure__items strong, .beike-puck-structure__chrome span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13px; }.beike-puck-structure__items small, .beike-puck-structure__chrome small { grid-column: 2; color: var(--beike-muted); font-size: 11px; }.beike-puck-structure__item-actions { display: flex; justify-content: flex-end; gap: 5px; }.beike-puck-structure__item-actions button { min-height: 32px !important; border: 1px solid var(--beike-hairline); border-radius: 5px; padding: 5px 7px; color: var(--beike-ink); background: #fff; font: inherit; font-size: 11px; font-weight: 700; cursor: pointer; }.beike-puck-structure__item-actions button:hover:not(:disabled) { border-color: #98a2b3; background: #f8fafc; }.beike-puck-structure__item-actions button:disabled { cursor: not-allowed; opacity: .42; }.beike-puck-structure__item-actions button.is-danger { border-color: #fecdca; color: #b42318; }.beike-puck-structure__item-actions button:focus-visible, .beike-puck-structure__item-select:focus-visible { outline: 3px solid var(--beike-focus); outline-offset: 2px; }.beike-puck-structure__back { justify-self: start; min-height: 34px; border: 1px solid var(--beike-hairline); border-radius: 6px; padding: 5px 8px; color: var(--beike-ink); background: #fff; font: inherit; font-size: 12px; cursor: pointer; }
    .beike-puck-storefront-preview { display: grid; grid-template-rows: auto minmax(0, 1fr); width: 100%; height: 100%; min-height: 0; overflow: hidden; background: #fff; }.beike-puck-storefront-preview > header { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 36px; border-bottom: 1px solid var(--puck-color-border); padding: 0 12px; color: var(--beike-muted); background: var(--puck-color-surface); font-size: 12px; }.beike-puck-storefront-preview > header strong { color: var(--beike-ink); font-size: 12px; }.beike-puck-storefront-preview > header output { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.beike-puck-storefront-preview iframe { display: block; width: 100%; min-width: 0; min-height: 0; height: 100%; border: 0; background: #fff; }.beike-puck-preview-target { position: relative; display: grid; gap: 4px; min-width: 176px; }.beike-puck-header-controls .beike-puck-preview-target label { display: grid; align-items: stretch; gap: 3px; }.beike-puck-preview-target input { min-height: 32px; border: 1px solid #98a2b3; border-radius: 6px; padding: 6px 8px; color: var(--beike-ink); background: #fff; font: inherit; font-size: 12px; }.beike-puck-preview-target small { color: var(--beike-muted); font-size: 11px; }.beike-puck-preview-target__selected { display: flex; min-height: 30px !important; align-items: center; justify-content: space-between; gap: 8px; border: 1px solid var(--beike-aloe) !important; border-radius: 6px !important; padding: 5px 8px !important; color: var(--beike-ink); background: var(--beike-pistachio) !important; font: inherit; font-size: 12px; cursor: pointer; }.beike-puck-preview-target__selected span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.beike-puck-preview-target__results { position: absolute; z-index: 20; top: calc(100% + 4px); left: 0; display: grid; width: min(340px, calc(100vw - 28px)); max-height: 280px; overflow: auto; border: 1px solid var(--beike-hairline); border-radius: 9px; padding: 5px; background: #fff; box-shadow: 0 14px 34px rgba(0,0,0,.18); }.beike-puck-preview-target__results button { display: flex; min-height: 40px; align-items: center; gap: 8px; border: 0; border-radius: 6px; padding: 6px; color: var(--beike-ink); background: #fff; font: inherit; font-size: 12px; text-align: left; cursor: pointer; }.beike-puck-preview-target__results button:hover { background: var(--beike-pistachio); }.beike-puck-preview-target__results img { width: 30px; height: 30px; flex: 0 0 30px; border-radius: 5px; object-fit: cover; }.beike-puck-preview-target__results span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .beike-puck-chrome-link { display: inline-flex; width: fit-content; min-height: 44px; align-items: center; border: 1px solid var(--beike-ink); border-radius: 9999px; padding: 8px 14px; color: #fff; background: var(--beike-ink); font-size: 12px; font-weight: 700; text-decoration: none; }.beike-puck-chrome-link:hover { color: var(--beike-ink); background: var(--beike-aloe); }.beike-puck-chrome-link:focus-visible { outline: 3px solid var(--beike-focus); outline-offset: 2px; }.beike-puck-chrome-card.is-footer-group { grid-template-columns: minmax(0, 1fr); align-items: stretch; }.beike-puck-chrome-nested { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr) auto; align-items: end; gap: 10px; border-top: 1px solid var(--beike-hairline); padding-top: 10px; }.beike-puck-chrome-payments { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }.beike-puck-chrome-payments label { display: flex; min-height: 44px; align-items: center; gap: 8px; border: 1px solid var(--beike-hairline); border-radius: 8px; padding: 8px 10px; background: #fff; }.beike-puck-chrome-payments input { width: 18px; height: 18px; }
    @media (max-width: 640px) { .beike-puck-section__hero { grid-template-columns: 1fr; }.beike-puck-section__image, .beike-puck-section__image-placeholder { min-height: 170px; } }
    @media (max-width: 980px) { .beike-puck-header-controls > label, .beike-puck-header-controls .beike-puck-preview-target, .beike-puck-header-controls > a, .beike-puck-header-controls .beike-puck-revisions { display: none; } }
    @media (max-width: 720px) { .beike-puck-header-controls { gap: 4px; padding-right: 2px; }.beike-puck-header-action { padding: 6px 8px; }.beike-puck-chrome-grid, .beike-puck-chrome-card, .beike-puck-chrome-nested, .beike-puck-structured-link { grid-template-columns: 1fr; }.beike-puck-chrome-payments { grid-template-columns: repeat(2, minmax(0, 1fr)); }.beike-puck-structured-link__picker, .beike-puck-structured-link__window { grid-column: auto; } }
    #beike-puck-builder [class*="_PuckHeader-inner_"] { grid-template-columns: auto auto minmax(0, 1fr); gap: 16px; }
    #beike-puck-builder [class*="_PuckHeader-tools_"] { min-width: 0; justify-content: flex-end; }
    .beike-puck-header-controls { flex-wrap: wrap; padding-block: 6px; }
    .beike-puck-header-controls > * { flex-shrink: 0; }
    .beike-puck-header-more > summary { white-space: nowrap; }
    .beike-puck-save-status { flex: 1 0 100%; order: 2; font-size: 12px; line-height: 1.4; color: #475467; text-align: right; overflow-wrap: anywhere; }
    .beike-puck-save-status.is-error { color: #b42318; }
    .beike-puck-save-status.is-saved { color: #006d3b; }
    .beike-puck-structure__item.is-selected { border-color: #2563eb; box-shadow: 0 0 0 1px #2563eb; }
    .beike-puck-structure__item.is-hidden .beike-puck-structure__item-select { color: #667085; background: #f8fafc; }
    .beike-puck-structure__item-actions { flex-wrap: wrap; }
    @media (max-width: 980px) {
      .beike-puck-header-controls { flex-wrap: wrap; }
      .beike-puck-header-controls > label, .beike-puck-header-controls .beike-puck-preview-target { display: grid; }
      .beike-puck-save-status { text-align: left; }
    }
    .beike-puck-preview-stage { position: relative; width: 100%; height: 100%; min-height: 0; overflow: hidden; background: #f1f5f9; }
    .beike-puck-preview-stage iframe { position: absolute; top: 0; transform-origin: top left; max-width: none; }
    .beike-puck-device-controls { display: flex; gap: 3px; flex-shrink: 0; }
    .beike-puck-device-controls button { border: 1px solid #d0d5dd; border-radius: 4px; background: #fff; color: #344054; font: inherit; white-space: nowrap; padding: 4px 7px; cursor: pointer; }
    .beike-puck-device-controls button[aria-pressed="true"] { border-color: #2563eb; color: #1d4ed8; background: #eff6ff; }
    .beike-puck-device-controls button:focus-visible { outline: 3px solid var(--beike-focus); outline-offset: 2px; }
    .beike-puck-localized-field { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; }
    .beike-puck-localized-field label { font-size: 13px; font-weight: 600; align-self: center; }
    .beike-puck-localized-field input, .beike-puck-localized-field textarea { grid-column: 1 / -1; width: 100%; box-sizing: border-box; border: 1px solid #d0d5dd; border-radius: 6px; padding: 10px; font: inherit; }
    .beike-puck-localized-field select { max-width: 110px; border: 1px solid #d0d5dd; border-radius: 4px; padding: 5px; font: inherit; }

    /* LONGOS Editor — final workbench layer. */
    #beike-puck-builder {
      --longos-brand: #155eef;
      --longos-brand-strong: #6d28d9;
      --longos-brand-soft: #eef4ff;
      --longos-ink: #162033;
      --longos-panel: #ffffff;
      --longos-canvas: #e9edf5;
      --longos-line: #d8deea;
      --longos-success: #087a55;
      --longos-danger: #c4320a;
      --puck-color-surface-muted: #f7f8fb;
      --puck-color-surface-subtle: #f1f4f9;
      --puck-color-border: var(--longos-line);
      --puck-color-border-hover: #9aa6bb;
      --puck-color-text: var(--longos-ink);
      --puck-color-text-secondary: #344054;
      --puck-color-text-muted: #667085;
      --puck-color-interactive: var(--longos-brand);
      --puck-color-interactive-hover: #004eeb;
      --puck-color-interactive-active: #0040c1;
      --puck-color-interactive-subtle: var(--longos-brand-soft);
      --puck-color-focus-ring: #528bff;
      --puck-color-selection-bg: #dbe8ff;
      --puck-color-selection-border: var(--longos-brand);
      --puck-header-color-bg: #101828;
      --puck-header-color-text: #ffffff;
      --puck-pluginbar-color-bg: #101828;
      --puck-pluginbar-color-text: #aeb8cc;
      --puck-pluginbar-color-text-selected: #ffffff;
      --puck-pluginbar-color-bg-hover: rgba(255,255,255,.08);
      --puck-pluginbar-color-text-hover: #ffffff;
      --puck-sidebar-left-color-bg: #f7f8fb;
      --puck-sidebar-right-color-bg: #ffffff;
      --puck-radius-m: 8px;
      --puck-radius-l: 12px;
      color: var(--longos-ink);
    }
    body { background: #101828; }
    #beike-puck-builder [class^="_PuckHeader_"], #beike-puck-builder [class*=" _PuckHeader_"] { border-bottom: 0; background: linear-gradient(112deg, #101828 0%, #17213a 58%, #21144a 100%); box-shadow: 0 8px 30px rgba(16,24,40,.22); }
    #beike-puck-builder [class*="_PuckHeader-inner_"] { min-height: 72px; align-items: center; border-left-color: rgba(255,255,255,.1); padding: 10px 16px; }
    #beike-puck-builder [class*="_PuckHeader-title_"] { display: flex; align-items: center; gap: 10px; min-width: max-content; color: #fff; font-size: 17px; font-weight: 800; letter-spacing: -.02em; }
    #beike-puck-builder [class*="_PuckHeader-title_"]::before { content: "L"; display: grid; place-items: center; width: 34px; height: 34px; border-radius: 10px; color: #fff; background: linear-gradient(135deg, var(--longos-brand), var(--longos-brand-strong)); box-shadow: 0 8px 20px rgba(21,94,239,.35); font-size: 16px; font-weight: 900; }
    #beike-puck-builder [class*="_PuckHeader-title_"]::after { content: "STUDIO"; margin-left: -4px; border: 1px solid rgba(255,255,255,.16); border-radius: 999px; padding: 3px 6px; color: #aeb8cc; font-size: 8px; font-weight: 800; letter-spacing: .12em; }
    #beike-puck-builder [class*="_PuckLayout-nav_"] { border-color: rgba(255,255,255,.08); box-shadow: 8px 0 24px rgba(16,24,40,.12); }
    #beike-puck-builder [class*="_Nav-list_"] { gap: 8px; padding-top: 18px; }
    #beike-puck-builder [class*="_NavItem-link_"] { margin-inline: 8px; width: calc(100% - 16px); border: 0; border-radius: 10px; padding-block: 9px; transition: background .18s ease, color .18s ease, transform .18s ease; }
    #beike-puck-builder [class*="_NavItem--active_"] > [class*="_NavItem-link_"] { border: 0; color: #fff; background: linear-gradient(135deg, var(--longos-brand), var(--longos-brand-strong)); box-shadow: 0 8px 18px rgba(21,94,239,.28); }
    #beike-puck-builder [class*="_NavItem_"]:not([class*="_NavItem--active_"]) > [class*="_NavItem-link_"]:hover { transform: translateY(-1px); }
    #beike-puck-builder [class*="_Sidebar--left_"] { border-color: var(--longos-line); box-shadow: 8px 0 24px rgba(16,24,40,.06); }
    #beike-puck-builder [class*="_Sidebar--right_"] { border-color: var(--longos-line); box-shadow: -8px 0 24px rgba(16,24,40,.05); }
    #beike-puck-builder [class*="_SidebarSection-title_"] { color: #475467; font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    #beike-puck-builder [class*="_PuckLayout-inner_"] { background: var(--longos-canvas); }

    .beike-puck-header-controls { display: grid; grid-template-columns: minmax(132px, .55fr) minmax(250px, 1fr) auto; align-items: center; gap: 10px; width: 100%; margin-left: auto; padding: 0; flex-wrap: nowrap; }
    .beike-puck-header-context, .beike-puck-header-actions { display: flex; align-items: center; gap: 7px; min-width: 0; }
    .beike-puck-header-actions { justify-content: flex-end; }
    .beike-puck-header-controls > .beike-puck-save-status { order: initial; flex: none; display: flex; align-items: center; gap: 9px; min-width: 0; margin: 0; border: 1px solid rgba(255,255,255,.1); border-radius: 10px; padding: 7px 10px; color: #d0d5dd; background: rgba(255,255,255,.06); text-align: left; }
    .beike-puck-save-status > i { width: 8px; height: 8px; flex: 0 0 8px; border-radius: 50%; background: #9aa6bb; box-shadow: 0 0 0 4px rgba(154,166,187,.12); }
    .beike-puck-save-status > span { display: grid; min-width: 0; }
    .beike-puck-save-status strong { color: #fff; font-size: 11px; line-height: 1.2; }
    .beike-puck-save-status small { max-width: 220px; overflow: hidden; color: #aeb8cc; font-size: 10px; line-height: 1.3; text-overflow: ellipsis; white-space: nowrap; }
    .beike-puck-save-status.is-saved > i { background: #32d583; box-shadow: 0 0 0 4px rgba(50,213,131,.13); }
    .beike-puck-save-status.is-saving > i, .beike-puck-save-status.is-publishing > i { background: #84adff; box-shadow: 0 0 0 4px rgba(132,173,255,.15); animation: longos-status-pulse 1.1s ease-in-out infinite; }
    .beike-puck-save-status.is-unsaved > i { background: #fdb022; box-shadow: 0 0 0 4px rgba(253,176,34,.14); }
    .beike-puck-save-status.is-error > i, .beike-puck-save-status.is-conflict > i { background: #f97066; box-shadow: 0 0 0 4px rgba(249,112,102,.14); }
    .beike-puck-save-status.is-offline > i { background: #f59e0b; box-shadow: 0 0 0 4px rgba(245,158,11,.14); }
    @keyframes longos-status-pulse { 50% { opacity: .35; transform: scale(.82); } }
    .beike-puck-header-controls > .beike-puck-header-context > label { display: flex; align-items: center; gap: 7px; color: #d0d5dd; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .beike-puck-header-controls select { min-height: 38px; border-color: rgba(255,255,255,.15); border-radius: 9px; padding-inline: 10px 28px; color: #fff; background: #25304a; }
    .beike-puck-header-controls select option { color: var(--longos-ink); background: #fff; }
    .beike-puck-header-action, .beike-puck-header-more > summary { min-height: 40px !important; border-color: rgba(255,255,255,.16); border-radius: 9px; color: #f2f4f7; background: rgba(255,255,255,.07); transition: border-color .18s ease, background .18s ease, transform .18s ease, box-shadow .18s ease; }
    .beike-puck-header-action:hover, .beike-puck-header-more > summary:hover, .beike-puck-header-action.is-active { border-color: rgba(255,255,255,.3); color: #fff; background: rgba(255,255,255,.13); transform: translateY(-1px); }
    .beike-puck-header-action kbd { margin-left: 7px; border: 1px solid rgba(255,255,255,.15); border-radius: 5px; padding: 2px 4px; color: #aeb8cc; background: rgba(0,0,0,.16); font: 8px/1.2 ui-monospace, monospace; }
    .beike-puck-header-action.is-primary { min-width: 104px; justify-content: center; border: 0; color: #fff; background: linear-gradient(135deg, var(--longos-brand), var(--longos-brand-strong)); box-shadow: 0 8px 20px rgba(21,94,239,.3); }
    .beike-puck-header-action.is-primary:hover { color: #fff; background: linear-gradient(135deg, #2970ff, #7f3eed); box-shadow: 0 10px 24px rgba(21,94,239,.4); }
    .beike-puck-header-action:disabled { transform: none; box-shadow: none; opacity: .48; }
    .beike-puck-header-more > summary::after { content: "···"; margin-left: 6px; letter-spacing: .08em; }
    .beike-puck-header-more > div { min-width: 210px; border-color: var(--longos-line); border-radius: 12px; padding: 7px; box-shadow: 0 20px 44px rgba(16,24,40,.22); }
    .beike-puck-header-more > div > a, .beike-puck-header-more > div > button { border-radius: 8px; font-size: 12px; }
    .beike-puck-header-more > div > a:hover, .beike-puck-header-more > div > button:hover { color: var(--longos-brand); background: var(--longos-brand-soft); }

    .beike-puck-existing-insert { gap: 16px; padding: 16px; }
    .beike-puck-existing-insert > header { border: 0; border-radius: 12px; padding: 16px; color: #fff; background: linear-gradient(140deg, #17213a, #2b1a55); box-shadow: 0 12px 28px rgba(16,24,40,.15); }
    .beike-puck-existing-insert > header strong { color: #fff; font-size: 15px; }
    .beike-puck-existing-insert > header p { color: #c7cede; }
    .beike-puck-existing-insert > label { gap: 7px; color: #475467; font-size: 11px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
    .beike-puck-existing-insert input { border-color: var(--longos-line); border-radius: 10px; padding: 10px 12px; background: #fff; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
    .beike-puck-starter-layouts { display: grid; gap: 8px; border: 1px solid #c7d7fe; border-radius: 13px; padding: 12px; background: linear-gradient(145deg, #f5f8ff, #faf7ff); }
    .beike-puck-starter-layouts > div { display: grid; gap: 3px; margin-bottom: 2px; }
    .beike-puck-starter-layouts h2 { margin: 0; color: var(--longos-brand); font-size: 11px; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; }
    .beike-puck-starter-layouts > div > span { color: #667085; font-size: 11px; line-height: 1.45; }
    .beike-puck-starter-layouts > button { display: flex; align-items: center; justify-content: space-between; gap: 8px; min-height: 56px; border: 1px solid #d7e2ff; border-radius: 10px; padding: 9px 10px; color: var(--longos-ink); background: rgba(255,255,255,.88); font: inherit; text-align: left; cursor: pointer; transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease; }
    .beike-puck-starter-layouts > button:hover { border-color: #84adff; box-shadow: 0 8px 18px rgba(21,94,239,.1); transform: translateY(-1px); }
    .beike-puck-starter-layouts button > span { display: grid; gap: 3px; min-width: 0; }
    .beike-puck-starter-layouts button strong { font-size: 12px; }
    .beike-puck-starter-layouts button small { overflow: hidden; color: #667085; font-size: 10px; text-overflow: ellipsis; white-space: nowrap; }
    .beike-puck-starter-layouts button em { flex: 0 0 auto; border-radius: 999px; padding: 5px 7px; color: #fff; background: linear-gradient(135deg, var(--longos-brand), var(--longos-brand-strong)); font-size: 9px; font-style: normal; font-weight: 800; }
    .beike-puck-existing-insert__group { gap: 8px; }
    .beike-puck-existing-insert__group h2 { color: #667085; font-size: 10px; letter-spacing: .08em; text-transform: uppercase; }
    .beike-puck-existing-insert__group button { border-color: var(--longos-line); border-radius: 11px; padding: 11px; background: #fff; box-shadow: 0 2px 7px rgba(16,24,40,.04); transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease; }
    .beike-puck-existing-insert__group button:hover:not(:disabled) { border-color: #84adff; background: #fff; box-shadow: 0 9px 20px rgba(21,94,239,.1); transform: translateY(-2px); }
    .beike-puck-existing-insert__group button em { border-radius: 999px; padding: 4px 7px; color: var(--longos-brand); background: var(--longos-brand-soft); font-size: 10px; font-style: normal; font-weight: 800; }

    .beike-puck-structure { gap: 14px; padding: 16px; }
    .beike-puck-structure > header { border-bottom: 1px solid var(--longos-line); padding-bottom: 12px; }
    .beike-puck-structure > header strong { font-size: 15px; letter-spacing: -.01em; }
    .beike-puck-structure__items, .beike-puck-structure__chrome { gap: 9px; }
    .beike-puck-structure__item { border-color: var(--longos-line); border-radius: 12px; padding: 8px; box-shadow: 0 2px 7px rgba(16,24,40,.035); transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease; }
    .beike-puck-structure__item:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(16,24,40,.08); }
    .beike-puck-structure__item.is-selected { border-color: var(--longos-brand); box-shadow: 0 0 0 3px rgba(21,94,239,.11), 0 10px 24px rgba(21,94,239,.1); }
    .beike-puck-structure__item-select, .beike-puck-structure__chrome button { border-radius: 8px; }
    .beike-puck-structure__item-actions { padding-top: 4px; border-top: 1px solid #eef1f6; }
    .beike-puck-structure__item-actions button { min-height: 32px !important; border-radius: 7px; }
    .beike-puck-structure__item-actions button:hover:not(:disabled) { border-color: #b2ccff; color: var(--longos-brand); background: var(--longos-brand-soft); }
    .beike-puck-undo { position: sticky; z-index: 4; bottom: 8px; display: flex; align-items: center; gap: 8px; border: 1px solid #344054; border-radius: 10px; padding: 8px 9px 8px 12px; color: #fff; background: #101828; box-shadow: 0 12px 28px rgba(16,24,40,.26); font-size: 12px; }
    .beike-puck-undo span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .beike-puck-undo button { min-height: 34px; border: 1px solid rgba(255,255,255,.3); border-radius: 7px; padding: 5px 9px; color: #fff; background: transparent; font: inherit; font-weight: 800; cursor: pointer; }
    .beike-puck-undo button:last-child { width: 34px; padding: 0; font-size: 18px; }
    .beike-puck-undo button:focus-visible { outline: 3px solid #84adff; outline-offset: 2px; }
    .beike-puck-anchor-field { display: grid; gap: 6px; }
    .beike-puck-anchor-field input { box-sizing: border-box; width: 100%; min-height: 44px; border: 1px solid #98a2b3; border-radius: 8px; padding: 9px 10px; color: var(--longos-ink); background: #fff; font: inherit; }
    .beike-puck-anchor-field input[aria-invalid="true"] { border-color: #d92d20; box-shadow: 0 0 0 2px rgba(217,45,32,.1); }
    .beike-puck-anchor-field small { color: #667085; font-size: 11px; line-height: 1.45; }

    .beike-puck-storefront-preview > header { min-height: 48px; border: 0; border-bottom: 1px solid var(--longos-line); padding: 0 14px; color: #667085; background: rgba(255,255,255,.94); backdrop-filter: blur(14px); }
    .beike-puck-storefront-preview > header strong { display: flex; align-items: center; gap: 7px; color: var(--longos-ink); font-size: 12px; }
    .beike-puck-storefront-preview > header strong::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: var(--longos-success); box-shadow: 0 0 0 4px rgba(8,122,85,.1); }
    .beike-puck-preview-stage { background-color: var(--longos-canvas); background-image: linear-gradient(rgba(102,112,133,.06) 1px, transparent 1px), linear-gradient(90deg, rgba(102,112,133,.06) 1px, transparent 1px); background-size: 24px 24px; }
    .beike-puck-preview-stage iframe { box-shadow: 0 20px 55px rgba(16,24,40,.18); }
    .beike-puck-device-controls { gap: 2px; border: 1px solid var(--longos-line); border-radius: 9px; padding: 3px; background: #f6f8fb; }
    .beike-puck-device-controls button { min-height: 30px !important; border: 0; border-radius: 6px; padding: 5px 9px; color: #667085; background: transparent; font-size: 11px; font-weight: 700; }
    .beike-puck-device-controls button { display: grid; grid-template-columns: auto auto; align-items: baseline; gap: 4px; }
    .beike-puck-device-controls button small { color: #94a3b8; font-size: 9px; font-weight: 600; }
    .beike-puck-device-controls button[aria-pressed="true"] { color: var(--longos-brand); background: #fff; box-shadow: 0 2px 6px rgba(16,24,40,.1); }

    .beike-puck-publish-dialog { box-sizing: border-box; width: min(580px, calc(100vw - 28px)); border: 0; border-radius: 20px; padding: 26px; color: var(--longos-ink); background: #fff; box-shadow: 0 28px 80px rgba(16,24,40,.34); }
    .beike-puck-publish-dialog::backdrop { background: rgba(8,14,28,.72); backdrop-filter: blur(5px); }
    .beike-puck-publish-dialog__mark { display: grid; place-items: center; width: 48px; height: 48px; margin-bottom: 18px; border-radius: 14px; color: #fff; background: linear-gradient(135deg, var(--longos-brand), var(--longos-brand-strong)); box-shadow: 0 12px 24px rgba(21,94,239,.28); font-size: 19px; font-weight: 900; }
    .beike-puck-publish-dialog__eyebrow { margin: 0 0 6px; color: var(--longos-brand); font-size: 10px; font-weight: 900; letter-spacing: .12em; }
    .beike-puck-publish-dialog h2 { margin: 0; font-size: clamp(21px, 4vw, 28px); letter-spacing: -.04em; }
    .beike-puck-publish-dialog__copy > p:last-child { margin: 9px 0 0; color: #667085; font-size: 13px; line-height: 1.55; }
    .beike-puck-publish-dialog dl { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 22px 0 14px; }
    .beike-puck-publish-dialog dl > div { border: 1px solid #e4e8f0; border-radius: 12px; padding: 12px; background: #f8faff; }
    .beike-puck-publish-dialog dt { color: #667085; font-size: 10px; font-weight: 700; }
    .beike-puck-publish-dialog dd { margin: 6px 0 0; color: var(--longos-ink); font-size: 17px; font-weight: 800; }
    .beike-puck-publish-dialog__note { margin: 0; border-radius: 10px; padding: 11px 12px; color: #344054; background: var(--longos-brand-soft); font-size: 11px; line-height: 1.55; }
    .beike-puck-publish-dialog__actions { display: flex; justify-content: flex-end; gap: 9px; margin-top: 22px; }
    .beike-puck-publish-dialog__actions button { min-height: 44px; border: 1px solid var(--longos-line); border-radius: 10px; padding: 9px 16px; color: var(--longos-ink); background: #fff; font: inherit; font-size: 13px; font-weight: 800; cursor: pointer; }
    .beike-puck-publish-dialog__actions button.is-primary { border: 0; color: #fff; background: linear-gradient(135deg, var(--longos-brand), var(--longos-brand-strong)); box-shadow: 0 9px 20px rgba(21,94,239,.25); }
    .beike-puck-publish-dialog__actions button:focus-visible { outline: 3px solid #84adff; outline-offset: 2px; }

    .beike-puck-theme-settings { display: grid; gap: 14px; padding: 16px; }
    .beike-puck-theme-settings > header { display: grid; gap: 5px; border-radius: 13px; padding: 16px; color: #fff; background: linear-gradient(140deg, #17213a, #2b1a55); box-shadow: 0 12px 28px rgba(16,24,40,.15); }
    .beike-puck-theme-settings > header strong { font-size: 15px; }
    .beike-puck-theme-settings > header p { margin: 0; color: #c7cede; font-size: 11px; line-height: 1.5; }
    .beike-puck-theme-settings fieldset { display: grid; gap: 11px; min-width: 0; margin: 0; border: 1px solid var(--longos-line); border-radius: 12px; padding: 13px; background: #fff; }
    .beike-puck-theme-settings legend { padding: 0 6px; color: #344054; font-size: 11px; font-weight: 900; letter-spacing: .05em; text-transform: uppercase; }
    .beike-puck-theme-settings fieldset > label:not(.beike-puck-theme-settings__toggle), .beike-puck-theme-settings__colors > label { display: grid; gap: 6px; color: #475467; font-size: 11px; font-weight: 750; }
    .beike-puck-theme-settings fieldset input[type="text"], .beike-puck-theme-settings fieldset textarea, .beike-puck-theme-settings fieldset select { min-height: 42px; border: 1px solid #d0d5dd; border-radius: 9px; padding: 8px 10px; color: var(--longos-ink); background: #fff; font: inherit; font-size: 12px; }
    .beike-puck-theme-settings__colors { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; }
    .beike-puck-theme-settings__colors label > span:last-child { display: flex; align-items: center; gap: 7px; border: 1px solid #e4e7ec; border-radius: 9px; padding: 6px; background: #f9fafb; }
    .beike-puck-theme-settings__colors input[type="color"] { width: 34px; height: 32px; flex: 0 0 34px; border: 0; border-radius: 7px; padding: 0; background: transparent; cursor: pointer; }
    .beike-puck-theme-settings__colors output { min-width: 0; overflow: hidden; color: #475467; font: 10px/1.2 ui-monospace, monospace; text-overflow: ellipsis; }
    .beike-puck-theme-settings__range { display: grid !important; gap: 7px; }
    .beike-puck-theme-settings__range > span { display: flex; justify-content: space-between; gap: 10px; }
    .beike-puck-theme-settings__range output { color: var(--longos-brand); font-weight: 800; }
    .beike-puck-theme-settings__range input { width: 100%; accent-color: var(--longos-brand); }
    .beike-puck-theme-settings__toggle { display: flex; align-items: flex-start; gap: 9px; border-radius: 9px; padding: 9px; color: #344054; background: #f8faff; cursor: pointer; }
    .beike-puck-theme-settings__toggle input { width: 17px; height: 17px; flex: 0 0 17px; margin-top: 1px; }
    .beike-puck-theme-settings__toggle span { display: grid; gap: 3px; }
    .beike-puck-theme-settings__toggle strong { font-size: 12px; }
    .beike-puck-theme-settings__toggle small, .beike-puck-theme-settings__hint { margin: 0; color: #667085; font-size: 10px; line-height: 1.45; }

    .beike-puck-rich-text-field { display: grid; gap: 8px; }
    .beike-puck-rich-text-field__head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .beike-puck-rich-text-field__head label { color: #344054; font-size: 12px; font-weight: 800; }
    .beike-puck-rich-text-field__head select { min-height: 32px !important; border: 1px solid var(--longos-line); border-radius: 8px; padding: 4px 24px 4px 8px; background: #fff; font-size: 11px; }
    .beike-puck-rich-text-field__toolbar { display: flex; flex-wrap: wrap; gap: 4px; border: 1px solid var(--longos-line); border-bottom: 0; border-radius: 10px 10px 0 0; padding: 6px; background: #f8faff; }
    .beike-puck-rich-text-field__toolbar button { min-height: 32px; border: 1px solid transparent; border-radius: 7px; padding: 4px 8px; color: #344054; background: transparent; font: inherit; font-size: 11px; cursor: pointer; }
    .beike-puck-rich-text-field__toolbar button:hover:not(:disabled), .beike-puck-rich-text-field__toolbar button:focus-visible { border-color: #b2ccff; color: var(--longos-brand); background: #fff; }
    .beike-puck-rich-text-field__editor { min-height: 180px; max-height: 420px; overflow: auto; border: 1px solid var(--longos-line); border-radius: 0 0 10px 10px; padding: 14px; color: var(--longos-ink); background: #fff; font-size: 13px; line-height: 1.65; outline: none; }
    .beike-puck-rich-text-field__editor:focus { border-color: #84adff; box-shadow: 0 0 0 3px rgba(21,94,239,.12); }
    .beike-puck-rich-text-field__editor:empty::before { content: "输入文本内容…"; color: #98a2b3; }
    .beike-puck-rich-text-field__editor h2, .beike-puck-rich-text-field__editor h3, .beike-puck-rich-text-field__editor p { margin: 0 0 .7em; }
    .beike-puck-rich-text-field > small { color: #667085; font-size: 10px; line-height: 1.45; }

    /* Stable three-zone workbench: navigation, controls, and the shopper view
       remain visually distinct even when Puck changes internal spacing. */
    #beike-puck-builder [class*="_Sidebar--left_"] { min-width: 300px; max-width: 340px; background: #fff; }
    #beike-puck-builder [class*="_Sidebar--right_"] { min-width: 340px; max-width: 390px; background: #fff; }
    #beike-puck-builder [class*="_Sidebar_"] input:not([type="checkbox"]):not([type="radio"]),
    #beike-puck-builder [class*="_Sidebar_"] textarea,
    #beike-puck-builder [class*="_Sidebar_"] select { box-sizing: border-box; width: 100%; border-color: #d0d5dd; border-radius: 9px; background-color: #fff; }
    #beike-puck-builder [class*="_Sidebar_"] input:focus-visible,
    #beike-puck-builder [class*="_Sidebar_"] textarea:focus-visible,
    #beike-puck-builder [class*="_Sidebar_"] select:focus-visible { border-color: #84adff; outline: 3px solid rgba(21,94,239,.13); outline-offset: 1px; }
    #beike-puck-builder [class*="_SidebarSection_"] { border-color: #eaecf0; }
    #beike-puck-builder [class*="_FieldLabel_"] { color: #344054; font-size: 11px; font-weight: 750; }

    #beike-puck-builder input, #beike-puck-builder textarea, #beike-puck-builder select { accent-color: var(--longos-brand); }
    #beike-puck-builder ::-webkit-scrollbar { width: 10px; height: 10px; }
    #beike-puck-builder ::-webkit-scrollbar-thumb { border: 3px solid transparent; border-radius: 999px; background: #aeb8cc; background-clip: padding-box; }
    #beike-puck-builder ::-webkit-scrollbar-track { background: transparent; }

    @media (max-width: 1279px) {
      .beike-puck-header-controls { grid-template-columns: auto minmax(210px, 1fr) auto; }
      .beike-puck-save-status small, .beike-puck-header-action kbd { display: none; }
      .beike-puck-header-controls > .beike-puck-save-status { min-height: 40px; box-sizing: border-box; }
      #beike-puck-builder [class*="_Sidebar--left_"] { min-width: 270px; max-width: 300px; }
      #beike-puck-builder [class*="_Sidebar--right_"] { min-width: 300px; max-width: 340px; }
    }
    @media (max-width: 1050px) {
      #beike-puck-builder [class*="_PuckHeader-title_"]::after, .beike-puck-header-controls .beike-puck-preview-target { display: none; }
      .beike-puck-header-action.is-chrome { font-size: 0; }
      .beike-puck-header-action.is-chrome::after { content: "页头/页尾"; font-size: 12px; }
    }
    @media (max-width: 820px) {
      #beike-puck-builder [class*="_Sidebar--left_"], #beike-puck-builder [class*="_Sidebar--right_"] { min-width: 0; max-width: none; }
      #beike-puck-builder [class*="_PuckHeader-inner_"] { gap: 8px; padding: 9px 10px; }
      #beike-puck-builder [class*="_PuckHeader-title_"] { font-size: 14px; }
      #beike-puck-builder [class*="_PuckHeader-title_"]::before { width: 30px; height: 30px; border-radius: 9px; }
      .beike-puck-header-controls { grid-template-columns: auto minmax(120px, 1fr) auto; gap: 6px; }
      .beike-puck-header-controls > .beike-puck-save-status { padding-inline: 9px; }
      .beike-puck-save-status > span { display: none; }
      .beike-puck-header-controls > .beike-puck-header-context > label { font-size: 0; }
      .beike-puck-header-controls select { min-width: 86px; padding-inline: 8px 20px; font-size: 11px; }
      .beike-puck-header-action { padding-inline: 8px; }
    }
    @media (max-width: 637px) {
      body { overflow: hidden; }
      #beike-puck-builder [class^="_PuckHeader_"], #beike-puck-builder [class*=" _PuckHeader_"] { max-height: none; }
      #beike-puck-builder [class*="_PuckHeader-inner_"] { grid-template-columns: auto minmax(0, 1fr); grid-template-areas: "left right"; }
      #beike-puck-builder [class*="_PuckHeader-tools_"] { min-width: 0; }
      .beike-puck-header-controls { grid-template-columns: auto 1fr; grid-template-areas: "status context" "actions actions"; width: 100%; }
      .beike-puck-header-controls > .beike-puck-save-status { grid-area: status; }
      .beike-puck-header-context { grid-area: context; justify-content: flex-end; }
      .beike-puck-header-actions { grid-area: actions; justify-content: flex-end; }
      .beike-puck-header-action.is-chrome { display: none; }
      .beike-puck-header-actions .beike-puck-header-action { flex: 1; justify-content: center; }
      .beike-puck-header-actions .beike-puck-header-more { flex: 0; }
      .beike-puck-header-more > div { right: 0; }
      #beike-puck-builder [class*="_Nav-list_"] { padding-top: 0; }
      #beike-puck-builder [class*="_NavItem-link_"] { margin: 0; width: 64px; }
      .beike-puck-storefront-preview > header { gap: 7px; padding-inline: 8px; }
      .beike-puck-storefront-preview > header output { display: none; }
      .beike-puck-publish-dialog { border-radius: 16px; padding: 20px; }
      .beike-puck-publish-dialog dl { grid-template-columns: 1fr; }
      .beike-puck-publish-dialog dl > div { display: flex; align-items: center; justify-content: space-between; }
      .beike-puck-publish-dialog dd { margin: 0; }
      .beike-puck-publish-dialog__actions button { flex: 1; }
    }
    @media (prefers-reduced-motion: reduce) {
      .beike-puck-save-status > i, .beike-puck-header-action, .beike-puck-existing-insert__group button, .beike-puck-structure__item { animation: none !important; transition: none !important; }
    }

    /* Professional workbench: quiet, task-first, and deliberately free of
       decorative gradients, pseudo branding, floating cards and grid paper. */
    #beike-puck-builder {
      --longos-brand: #1d4ed8;
      --longos-brand-strong: #1d4ed8;
      --longos-brand-soft: #eff6ff;
      --longos-ink: #111827;
      --longos-canvas: #eef0f3;
      --longos-line: #d9dde5;
      --puck-header-color-bg: #fff;
      --puck-header-color-text: #111827;
      --puck-pluginbar-color-bg: #fff;
      --puck-pluginbar-color-text: #64748b;
      --puck-pluginbar-color-text-selected: #1d4ed8;
      --puck-pluginbar-color-bg-hover: #f1f5f9;
      --puck-pluginbar-color-text-hover: #111827;
    }
    body { background: #eef0f3; }
    #beike-puck-builder [class^="_PuckHeader_"], #beike-puck-builder [class*=" _PuckHeader_"] { border-bottom: 1px solid var(--longos-line); background: #fff; box-shadow: none; }
    #beike-puck-builder [class*="_PuckHeader-inner_"] { min-height: 64px; border-left-color: var(--longos-line); padding: 8px 14px; }
    #beike-puck-builder [class*="_PuckHeader-title_"] { color: #111827; font-size: 15px; font-weight: 750; letter-spacing: -.01em; }
    #beike-puck-builder [class*="_PuckHeader-title_"]::before,
    #beike-puck-builder [class*="_PuckHeader-title_"]::after { content: none; display: none; }
    #beike-puck-builder [class*="_PuckLayout-nav_"] { border-color: var(--longos-line); box-shadow: none; }
    #beike-puck-builder [class*="_Nav-list_"] { gap: 2px; padding-top: 8px; }
    #beike-puck-builder [class*="_NavItem-link_"] { margin-inline: 4px; width: calc(100% - 8px); border-radius: 6px; transition: background-color .15s ease, color .15s ease; }
    #beike-puck-builder [class*="_NavItem--active_"] > [class*="_NavItem-link_"] { color: #1d4ed8; background: #eff6ff; box-shadow: inset 3px 0 #1d4ed8; }
    #beike-puck-builder [class*="_NavItem_"]:not([class*="_NavItem--active_"]) > [class*="_NavItem-link_"]:hover { transform: none; background: #f6f7f9; }
    #beike-puck-builder [class*="_Sidebar--left_"], #beike-puck-builder [class*="_Sidebar--right_"] { border-color: var(--longos-line); box-shadow: none; }
    #beike-puck-builder [class*="_PuckLayout-inner_"] { background: #eef0f3; }

    .beike-puck-header-controls > .beike-puck-save-status { border-color: #e2e8f0; border-radius: 7px; color: #475569; background: #f8fafc; }
    .beike-puck-save-status strong { color: #111827; }
    .beike-puck-save-status small { color: #64748b; }
    .beike-puck-header-controls > .beike-puck-header-context > label { color: #475569; }
    .beike-puck-header-controls select { border-color: #cbd5e1; border-radius: 7px; color: #111827; background: #fff; }
    .beike-puck-header-action, .beike-puck-header-more > summary { border-color: #cbd5e1; border-radius: 7px; color: #1f2937; background: #fff; }
    .beike-puck-header-action:hover, .beike-puck-header-more > summary:hover, .beike-puck-header-action.is-active { border-color: #94a3b8; color: #111827; background: #f8fafc; transform: none; }
    .beike-puck-header-action kbd { border-color: #dbe1e8; color: #64748b; background: #f1f5f9; }
    .beike-puck-header-action.is-primary, .beike-puck-header-action.is-primary:hover { color: #fff; background: #1d4ed8; box-shadow: none; }
    .beike-puck-header-more > summary::after { content: "⌄"; letter-spacing: normal; }

    .beike-puck-existing-insert, .beike-puck-structure, .beike-puck-theme-settings { gap: 12px; padding: 14px; }
    .beike-puck-existing-insert > header, .beike-puck-theme-settings > header { border: 0; border-bottom: 1px solid var(--longos-line); border-radius: 0; padding: 0 0 12px; color: #111827; background: transparent; box-shadow: none; }
    .beike-puck-existing-insert > header strong, .beike-puck-theme-settings > header strong { color: #111827; font-size: 14px; }
    .beike-puck-existing-insert > header p, .beike-puck-theme-settings > header p { color: #64748b; }
    .beike-puck-starter-layouts { gap: 6px; border-color: var(--longos-line); border-radius: 8px; padding: 10px; background: #f8fafc; }
    .beike-puck-starter-layouts h2 { color: #334155; }
    .beike-puck-starter-layouts > button { min-height: 52px; border-color: #dce1e8; border-radius: 6px; background: #fff; transition: border-color .15s ease, background-color .15s ease; }
    .beike-puck-starter-layouts > button:hover { border-color: #94a3b8; background: #f8fafc; box-shadow: none; transform: none; }
    .beike-puck-starter-layouts button em, .beike-puck-existing-insert__group button em { border: 0; border-radius: 5px; color: #1d4ed8; background: #eff6ff; }
    .beike-puck-existing-insert__group button { border-color: transparent; border-bottom-color: #e5e7eb; border-radius: 0; padding: 10px 4px; box-shadow: none; }
    .beike-puck-existing-insert__group button:hover:not(:disabled) { border-color: transparent; border-bottom-color: #cbd5e1; background: #f8fafc; box-shadow: none; transform: none; }
    .beike-puck-starter-layouts > button:disabled, .beike-puck-existing-insert__group button:disabled { cursor: not-allowed; opacity: .55; transform: none; }
    .beike-puck-existing-insert__search { display: grid; gap: 6px; }
    .beike-puck-existing-insert__search > label { color: #334155; font-size: 11px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
    .beike-puck-existing-insert__search > div { display: flex; align-items: center; gap: 6px; }
    .beike-puck-existing-insert__search input { flex: 1; min-width: 0; }
    .beike-puck-existing-insert__search button, .beike-puck-existing-insert__filters button { min-height: 36px; border: 1px solid #d7dce4; border-radius: 7px; padding: 7px 9px; color: #334155; background: #fff; font: inherit; font-size: 11px; font-weight: 700; cursor: pointer; }
    .beike-puck-existing-insert__filters { display: flex; gap: 6px; overflow-x: auto; padding: 1px 1px 4px; scrollbar-width: thin; }
    .beike-puck-existing-insert__filters button { display: inline-flex; flex: 0 0 auto; align-items: center; gap: 5px; white-space: nowrap; }
    .beike-puck-existing-insert__filters button span { display: grid; min-width: 20px; height: 20px; place-items: center; border-radius: 999px; color: #64748b; background: #f1f5f9; font-size: 10px; }
    .beike-puck-existing-insert__filters button.is-active { border-color: #1d4ed8; color: #1d4ed8; background: #eff6ff; }
    .beike-puck-existing-insert__filters button.is-active span { color: #fff; background: #1d4ed8; }
    .beike-puck-existing-insert__result { margin: -4px 0 0; color: #64748b; font-size: 11px; }
    .beike-puck-existing-insert__group button strong { display: flex; align-items: center; gap: 6px; }
    .beike-puck-existing-insert__group button strong i { flex: 0 0 auto; color: #94a3b8; font-size: 9px; font-style: normal; font-weight: 700; }

    .beike-puck-module-inspector { display: grid; gap: 0; }
    .beike-puck-module-inspector > header { display: grid; gap: 5px; margin: 14px; border: 1px solid #d9e2f2; border-radius: 8px; padding: 12px; background: #f8fbff; }
    .beike-puck-module-inspector > header > strong { color: #0f172a; font-size: 15px; }
    .beike-puck-module-inspector > header > p { margin: 0; color: #64748b; font-size: 11px; line-height: 1.5; }
    .beike-puck-module-inspector__eyebrow { color: #1d4ed8; font-size: 10px; font-weight: 850; letter-spacing: .08em; text-transform: uppercase; }
    .beike-puck-module-inspector > header > div { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 3px; }
    .beike-puck-module-inspector > header > div > span { border-radius: 999px; padding: 4px 7px; color: #475569; background: #e9eef5; font-size: 10px; font-weight: 700; }
    .beike-puck-module-inspector > header > div > span.is-positive { color: #067647; background: #ecfdf3; }
    .beike-puck-module-inspector > header > div > span.is-warning { color: #92400e; background: #fffbeb; }
    .beike-puck-module-inspector.is-empty > header { border-style: dashed; background: #f8fafc; }

    .beike-puck-responsive-field, .beike-puck-spacing-field { display: grid; gap: 9px; margin: 0; border: 0; padding: 0; }
    .beike-puck-responsive-field legend, .beike-puck-spacing-field legend { padding: 0; color: #64748b; font-size: 11px; line-height: 1.45; }
    .beike-puck-responsive-field > div { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
    .beike-puck-responsive-field label { display: flex; min-width: 0; min-height: 52px; align-items: center; gap: 6px; border: 1px solid #d9dde5; border-radius: 7px; padding: 7px; color: #64748b; background: #fff; cursor: pointer; }
    .beike-puck-responsive-field label.is-enabled { border-color: #93b4ff; color: #1d4ed8; background: #eff6ff; }
    .beike-puck-responsive-field input { width: 17px; height: 17px; flex: 0 0 auto; }
    .beike-puck-responsive-field label span { display: grid; min-width: 0; gap: 1px; }
    .beike-puck-responsive-field label strong { font-size: 11px; }
    .beike-puck-responsive-field label small { color: #64748b; font-size: 9px; white-space: nowrap; }
    .beike-puck-responsive-field > p, .beike-puck-spacing-field > p { margin: 0; color: #64748b; font-size: 10px; line-height: 1.45; }
    .beike-puck-responsive-field > p[role="alert"] { color: #b54708; font-weight: 700; }
    .beike-puck-spacing-field__mode { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    .beike-puck-spacing-field__mode label { display: flex; min-height: 42px; align-items: center; gap: 6px; border: 1px solid #d9dde5; border-radius: 7px; padding: 7px; font-size: 11px; cursor: pointer; }
    .beike-puck-spacing-field__mode label:has(input:checked) { border-color: #93b4ff; color: #1d4ed8; background: #eff6ff; }
    .beike-puck-spacing-field__mode input { width: 16px; height: 16px; }
    .beike-puck-spacing-field__values { display: grid; gap: 8px; }
    .beike-puck-spacing-field__values label { display: grid; grid-template-columns: 38px minmax(0, 1fr) 58px 16px; align-items: center; gap: 6px; color: #475569; font-size: 11px; }
    .beike-puck-spacing-field__values input[type="range"] { width: 100%; }
    .beike-puck-spacing-field__values input[type="number"] { box-sizing: border-box; width: 58px; min-height: 38px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px; font: inherit; }
    .beike-puck-spacing-field__values small { color: #94a3b8; font-size: 10px; }

    .beike-puck-color-field { flex-wrap: wrap; }
    .beike-puck-color-field .beike-puck-color-field__hex { box-sizing: border-box; width: auto; min-width: 0; flex: 1; border: 1px solid #98a2b3; border-radius: 6px; padding: 7px 8px; color: #334155; background: #fff; font: 12px ui-monospace, SFMono-Regular, Consolas, monospace; }
    .beike-puck-color-field .beike-puck-color-field__hex[aria-invalid="true"] { border-color: #d92d20; }
    .beike-puck-color-field button { min-height: 38px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 8px; color: #475569; background: #fff; font: inherit; font-size: 11px; cursor: pointer; }
    .beike-puck-color-field small { flex-basis: 100%; color: #64748b; font-size: 10px; }
    .beike-puck-localized-field { grid-template-columns: 1fr; }
    .beike-puck-localized-field__head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .beike-puck-localized-field__count { justify-self: end; margin-top: -4px; color: #94a3b8; font-size: 10px; }
    .beike-puck-commerce-field__selected-item { display: flex; min-width: 0; align-items: center; justify-content: space-between; gap: 8px; border: 1px solid var(--longos-line); border-radius: 7px; padding: 7px 8px; background: #fff; }
    .beike-puck-commerce-field__selected-item > span { display: grid; min-width: 0; gap: 2px; }
    .beike-puck-commerce-field__selected-item strong, .beike-puck-commerce-field__selected-item small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .beike-puck-commerce-field__selected-item strong { color: var(--longos-ink); font-size: 11px; }
    .beike-puck-commerce-field__selected-item small { color: #64748b; font-size: 10px; }
    .beike-puck-commerce-field__selected-item > div { display: flex; flex: 0 0 auto; gap: 4px; }
    .beike-puck-commerce-field__selected .beike-puck-commerce-field__selected-item button { display: inline-grid; min-width: 34px; min-height: 34px; place-items: center; border: 1px solid #d8dee8; border-radius: 6px; padding: 5px 7px; color: #334155; background: #fff; font: inherit; font-size: 11px; font-weight: 700; cursor: pointer; }
    .beike-puck-commerce-field__selected .beike-puck-commerce-field__selected-item button:hover:not(:disabled) { border-color: #94a3b8; background: #f8fafc; }
    .beike-puck-commerce-field__selected .beike-puck-commerce-field__selected-item button.is-danger { color: var(--longos-danger); }
    .beike-puck-commerce-field__selected .beike-puck-commerce-field__selected-item button:disabled { cursor: not-allowed; opacity: .4; }
    .beike-puck-commerce-field__item.is-selected { border-color: #93b4ff; background: var(--longos-brand-soft); opacity: 1; }

    .beike-puck-structure > header { padding-bottom: 10px; }
    .beike-puck-structure__global { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; border-bottom: 1px solid var(--longos-line); padding-bottom: 12px; }
    .beike-puck-structure__global > span { grid-column: 1 / -1; display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
    .beike-puck-structure__global > span strong { color: #334155; font-size: 11px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; }
    .beike-puck-structure__global > span small { color: #94a3b8; font-size: 10px; }
    .beike-puck-structure__global button { display: grid; gap: 3px; min-height: 58px; border: 1px solid #d9dde5; border-radius: 7px; padding: 9px; color: #111827; background: #fff; font: inherit; text-align: left; cursor: pointer; }
    .beike-puck-structure__global button:hover { border-color: #94a3b8; background: #f8fafc; }
    .beike-puck-structure__global button span { font-size: 12px; font-weight: 750; }
    .beike-puck-structure__global button small { color: #64748b; font-size: 10px; line-height: 1.35; }
    .beike-puck-structure__item { border-radius: 7px; box-shadow: none; transition: border-color .15s ease, background-color .15s ease; }
    .beike-puck-structure__item:hover { transform: none; box-shadow: none; background: #f8fafc; }
    .beike-puck-structure__item.is-selected { border-color: #1d4ed8; box-shadow: inset 3px 0 #1d4ed8; }

    .beike-puck-storefront-preview > header { background: #fff; backdrop-filter: none; }
    .beike-puck-preview-stage { background: #e7e9ed; background-image: none; }
    .beike-puck-preview-stage iframe { box-shadow: 0 1px 3px rgba(15,23,42,.16); }
    .beike-puck-device-controls { border-radius: 7px; }

    .beike-puck-chrome-dialog__head { padding: 14px; }
    .beike-puck-chrome-tabs { gap: 0; padding: 0 14px; }
    .beike-puck-chrome-tabs button { border: 0; border-bottom: 2px solid transparent; border-radius: 0; padding-inline: 10px; color: #64748b; background: transparent; }
    .beike-puck-chrome-tabs button.is-active { border-color: #1d4ed8; color: #1d4ed8; background: transparent; }
    .beike-puck-chrome-dialog__body { gap: 12px; }
    .beike-puck-chrome-section { border: 0; border-bottom: 1px solid var(--longos-line); border-radius: 0; padding: 0 0 16px; }
    .beike-puck-chrome-section:last-child { border-bottom: 0; }
    .beike-puck-chrome-card { grid-template-columns: 1fr; align-items: stretch; border-radius: 7px; background: #f8fafc; }
    .beike-puck-chrome-dialog button { border-color: #cbd5e1; border-radius: 7px; }
    .beike-puck-chrome-dialog__foot { align-items: center; }
    .beike-puck-chrome-dialog__foot output { margin-right: auto; color: #64748b; font-size: 11px; }
    .beike-puck-chrome-dialog__foot output.is-error { color: #b42318; font-weight: 700; }
    .beike-puck-chrome-dialog__foot .is-primary { border-color: #1d4ed8; color: #fff; background: #1d4ed8; }
    .beike-puck-chrome-media { display: grid; gap: 7px; }
    .beike-puck-chrome-media > span { color: #111827; font-size: 12px; font-weight: 700; }
    .beike-puck-chrome-media > small { color: #64748b; font-size: 10px; }
    .beike-puck-chrome-media .beike-puck-media-field__preview { min-height: 72px; }
    .beike-puck-chrome-media.is-compact .beike-puck-media-field__preview { min-height: 56px; }
    .beike-puck-chrome-link { display: inline-flex; width: fit-content; min-height: 40px; align-items: center; border: 1px solid #cbd5e1; border-radius: 7px; padding: 7px 10px; color: #1d4ed8; background: #fff; font-size: 12px; font-weight: 700; text-decoration: none; }
    .beike-puck-chrome-link:hover { border-color: #94a3b8; background: #f8fafc; }

    .beike-puck-theme-settings fieldset { border-radius: 7px; }
    .beike-puck-publish-dialog { border-radius: 10px; }
    .beike-puck-publish-dialog__mark { border-radius: 7px; background: #1d4ed8; box-shadow: none; }
    .beike-puck-publish-dialog__actions button { border-radius: 7px; }
    .beike-puck-publish-dialog__actions button.is-primary { background: #1d4ed8; box-shadow: none; }
    @media (max-width: 637px) {
      #beike-puck-builder [class*="_PuckLayout--leftSideBarVisible_"] [class*="_PuckLayout-inner_"] { grid-template-rows: 0 minmax(180px, 1fr) minmax(250px, 44dvh) 0 60px; }
      #beike-puck-builder [class*="_PuckLayout--leftSideBarVisible_"][class*="_PuckLayout--isExpanded_"] [class*="_PuckLayout-inner_"] { grid-template-rows: 0 minmax(120px, 1fr) minmax(320px, 62dvh) 0 60px; }
      #beike-puck-builder [class*="_PuckHeader-title_"] { display: none; }
      #beike-puck-builder [class*="_PuckHeader-inner_"] { display: block; min-height: 0; padding: 8px; }
      #beike-puck-builder [class*="_PuckHeader-tools_"] { width: 100%; }
      .beike-puck-header-controls { grid-template-columns: minmax(0, 1fr) auto; grid-template-areas: "context actions" "status status"; gap: 6px; }
      .beike-puck-header-controls > .beike-puck-save-status { grid-area: status; min-height: 32px; padding-block: 5px; }
      .beike-puck-save-status > span { display: grid; }
      .beike-puck-save-status small { max-width: none; }
      .beike-puck-header-context { grid-area: context; justify-content: flex-start; }
      .beike-puck-header-actions { grid-area: actions; }
      .beike-puck-header-actions .beike-puck-header-action:not(.is-primary), .beike-puck-header-action.is-chrome { display: none; }
      .beike-puck-header-action.is-primary { min-width: 92px; }
      .beike-puck-storefront-preview > header { min-height: 42px; padding-inline: 8px; }
      .beike-puck-storefront-preview > header > strong, .beike-puck-storefront-preview > header > output { display: none; }
      .beike-puck-device-controls { width: 100%; justify-content: center; overflow-x: auto; }
      .beike-puck-device-controls button { min-width: 62px; justify-content: center; }
      .beike-puck-device-controls button small { display: none; }
      .beike-puck-structure__item-actions { justify-content: flex-start; }
      .beike-puck-structure__item-actions button { flex: 1 1 calc(33.333% - 5px); }
      .beike-puck-chrome-panel .beike-puck-chrome-dialog__body { max-height: none; }
      .beike-puck-chrome-dialog__foot { position: sticky; bottom: 0; flex-wrap: wrap; background: #fff; }
      .beike-puck-chrome-dialog__foot output { flex: 1 0 100%; }
      .beike-puck-media-dialog { width: 100vw; max-width: none; height: 100dvh; max-height: none; border-radius: 0; }
      .beike-puck-media-dialog iframe { height: calc(100dvh - 54px); }
    }
  </style>
</head>
<body>
  <main id="beike-puck-builder" aria-label="LONGOS 店铺编辑器"></main>
  <script>
    window.BeikePuckBootstrap = {
      catalog: @json($editor_catalog ?? []),
      shopifyUnits: @json($shopify_units ?? []),
      shopifyRegistry: @json($shopify_registry ?? []),
      appEmbeds: @json($editor_app_embeds ?? []),
      editorLinkTypes: @json($editor_link_types ?? []),
      puckData: @json($puck_data ?? ['content' => [], 'root' => ['props' => []]]),
      updateUrl: @json(admin_route('design.update')),
      previewModuleUrl: @json(admin_route('design.preview_module')),
      mediaLibraryUrl: @json($file_manager_url ?? ''),
      videoMediaLibraryUrl: @json($video_file_manager_url ?? ''),
      entityAutocompleteUrls: @json($entity_autocomplete_urls ?? []),
      revisionsUrl: @json(admin_route('design.revisions')),
      restoreRevisionUrl: @json(admin_route('design.revisions.restore', ['revision' => '__revision__'])),
      storefrontBaseUrl: @json($storefront_base_url ?? url('/')),
      storefrontPreviewUrl: @json($storefront_preview_url ?? url('/?design=1')),
      navigationEditorUrl: @json($navigation_editor_url ?? admin_route('design.navigation.index')),
      policySettingsUrl: @json(admin_route('settings.policies')),
      productPreviewUrl: @json($product_preview_url ?? url('/?design=1')),
      categoryPreviewUrl: @json($category_preview_url ?? url('/?design=1')),
      pagePreviewUrl: @json($page_preview_url ?? url('/?design=1')),
      exportUrl: @json(admin_route('design.export')),
      importUrl: @json(admin_route('design.import')),
      swiperStyleUrl: @json($swiper_style_url ?? ''),
      swiperScriptUrl: @json($swiper_script_url ?? ''),
      locale: @json(locale()),
      headerSettings: @json($header_settings ?? []),
      footerSettings: @json($footer_settings ?? []),
      menuSettings: @json($menu_settings ?? ['menus' => []]),
      csrfToken: @json(csrf_token()),
      draftVersion: @json((int) ($draft_version ?? 0)),
      hasUnpublishedChanges: @json((bool) ($has_unpublished_changes ?? false))
    };
  </script>
</body>
</html>
