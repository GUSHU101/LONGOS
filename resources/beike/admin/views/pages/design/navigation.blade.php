@extends('admin::layouts.master')

@section('title', __('admin/design_navigation.title'))
@section('content-area-class', 'w-max-1600')

@push('header')
  <style>
    [v-cloak] { display: none !important; }
    .nav-workspace { --nav-blue: #155eef; --nav-ink: #101828; --nav-muted: #667085; --nav-line: #e4e7ec; display: grid; gap: 1.25rem; grid-template-columns: minmax(0, 1fr) minmax(300px, 380px); margin-top: 1rem; }
    .nav-workspace__head { align-items: flex-start; display: flex; gap: 1rem; justify-content: space-between; margin-bottom: 1rem; }
    .nav-workspace__head h2 { color: var(--nav-ink); font-size: 1.25rem; margin: 0 0 .35rem; }
    .nav-workspace__head p { color: var(--nav-muted); margin: 0; }
    .nav-workspace__status { align-items: center; background: #f2f4f7; border-radius: 999px; color: #475467; display: inline-flex; flex: 0 0 auto; font-size: .75rem; font-weight: 700; gap: .4rem; padding: .5rem .75rem; }
    .nav-workspace__status.is-dirty { background: #fffaeb; color: #93370d; }
    .nav-workspace__status.is-saving { background: #eff8ff; color: #175cd3; }
    .nav-workspace__status.is-success { background: #ecfdf3; color: #027a48; }
    .nav-workspace__message { border-radius: .625rem; margin: 0 0 1rem; padding: .75rem 1rem; }
    .nav-builder-list { display: grid; gap: .875rem; }
    .nav-builder-empty { border: 1px dashed #b8c2d3; border-radius: .875rem; color: var(--nav-muted); padding: 3rem 1rem; text-align: center; }
    .nav-builder-card { background: #fff; border: 1px solid var(--nav-line); border-radius: .875rem; box-shadow: 0 4px 14px rgba(16,24,40,.04); overflow: hidden; }
    .nav-builder-card__head { align-items: center; background: #f8faff; border-bottom: 1px solid var(--nav-line); display: flex; gap: .75rem; padding: .75rem 1rem; }
    .nav-builder-card__index { align-items: center; background: #e8f0ff; border-radius: .5rem; color: var(--nav-blue); display: inline-flex; font-size: .75rem; font-weight: 800; height: 2rem; justify-content: center; width: 2rem; }
    .nav-builder-card__head strong { color: var(--nav-ink); flex: 1; }
    .nav-builder-card__actions { display: flex; gap: .3rem; }
    .nav-builder-card__actions button { align-items: center; background: #fff; border: 1px solid var(--nav-line); border-radius: .45rem; color: #475467; display: inline-flex; height: 2rem; justify-content: center; min-width: 2rem; padding: 0 .5rem; }
    .nav-builder-card__actions button:hover:not(:disabled) { border-color: #84adff; color: var(--nav-blue); }
    .nav-builder-card__actions button:disabled { cursor: not-allowed; opacity: .42; }
    .nav-builder-card__actions .is-danger:hover { border-color: #fda29b; color: #b42318; }
    .nav-builder-card__body { display: grid; gap: 1rem; padding: 1rem; }
    .nav-form-grid { display: grid; gap: .875rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .nav-form-grid .span-2 { grid-column: 1 / -1; }
    .nav-field { display: grid; gap: .4rem; min-width: 0; }
    .nav-field > span { color: #344054; font-size: .78rem; font-weight: 700; }
    .nav-field :is(input, select) { background: #fff; border: 1px solid #d0d5dd; border-radius: .5rem; color: var(--nav-ink); min-height: 2.75rem; padding: .55rem .7rem; width: 100%; }
    .nav-field input[type="color"] { cursor: pointer; padding: .25rem; }
    .nav-field :is(input, select):focus-visible, .nav-toggle input:focus-visible, .nav-builder-card button:focus-visible { border-color: #84adff; box-shadow: 0 0 0 3px rgba(21,94,239,.13); outline: none; }
    .nav-link-fields { display: grid; gap: .6rem; grid-template-columns: minmax(135px, .7fr) minmax(0, 1.3fr); }
    .nav-link-hint { color: var(--nav-muted); font-size: .72rem; grid-column: 1 / -1; margin: -.15rem 0 0; }
    .nav-link-picker { display: grid; gap: .65rem; }
    .nav-link-picker__type { display: grid; gap: .4rem; grid-template-columns: minmax(135px, .7fr) minmax(0, 1.3fr); }
    .nav-link-picker__type > select, .nav-link-picker__direct :is(input, select), .nav-link-picker__search input { background: #fff; border: 1px solid #d0d5dd; border-radius: .625rem; color: var(--nav-ink); min-height: 2.75rem; padding: .55rem .75rem; width: 100%; }
    .nav-link-picker__direct { display: grid; gap: .4rem; }
    .nav-link-picker__selected { align-items: center; background: #f8faff; border: 1px solid #c7d7fe; border-radius: .75rem; display: grid; gap: .75rem; grid-template-columns: 3rem minmax(0, 1fr) auto; padding: .7rem; }
    .nav-link-picker__selected.is-missing { background: #fffaeb; border-color: #fedf89; grid-template-columns: 2.5rem minmax(0, 1fr) auto; }
    .nav-link-picker__thumb { align-items: center; background: #fff; border: 1px solid #dfe5ef; border-radius: .6rem; color: #667085; display: flex; height: 3rem; justify-content: center; overflow: hidden; width: 3rem; }
    .nav-link-picker__thumb img { height: 100%; object-fit: cover; width: 100%; }
    .nav-link-picker__copy { min-width: 0; }
    .nav-link-picker__copy strong, .nav-link-picker__copy small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .nav-link-picker__copy strong { color: var(--nav-ink); font-size: .85rem; }
    .nav-link-picker__copy small { color: var(--nav-muted); font-size: .72rem; margin-top: .2rem; }
    .nav-link-picker__actions { display: flex; gap: .35rem; }
    .nav-link-picker__actions button { background: #fff; border: 1px solid #d0d5dd; border-radius: .55rem; color: #344054; min-height: 2.75rem; padding: .45rem .7rem; }
    .nav-link-picker__actions button:last-child { color: #b42318; }
    .nav-link-picker__search { display: grid; gap: .45rem; position: relative; }
    .nav-link-picker__input { position: relative; }
    .nav-link-picker__input i { color: #667085; left: .85rem; position: absolute; top: 50%; transform: translateY(-50%); }
    .nav-link-picker__input input { padding-left: 2.35rem; padding-right: 4.5rem; }
    .nav-link-picker__input button { background: transparent; border: 0; color: var(--nav-blue); font-size: .75rem; font-weight: 750; min-height: 2.75rem; padding: 0 .7rem; position: absolute; right: 0; top: 0; }
    .nav-link-picker__status { color: var(--nav-muted); font-size: .72rem; min-height: 1rem; }
    .nav-link-picker__status.is-error { color: #b42318; }
    .nav-link-picker__results { background: #fff; border: 1px solid var(--nav-line); border-radius: .75rem; box-shadow: 0 12px 28px rgba(16,24,40,.1); display: grid; gap: .25rem; max-height: 18rem; overflow-y: auto; padding: .4rem; }
    .nav-link-picker__result { align-items: center; background: #fff; border: 0; border-radius: .55rem; color: var(--nav-ink); display: grid; gap: .65rem; grid-template-columns: 2.5rem minmax(0, 1fr) auto; min-height: 3.5rem; padding: .45rem .55rem; text-align: left; width: 100%; }
    .nav-link-picker__result:hover:not(:disabled), .nav-link-picker__result:focus-visible { background: #eef4ff; }
    .nav-link-picker__result:disabled { cursor: not-allowed; opacity: .56; }
    .nav-link-picker__result .nav-link-picker__thumb { height: 2.5rem; width: 2.5rem; }
    .nav-link-picker__state { border-radius: 999px; color: #027a48; font-size: .68rem; font-weight: 700; padding: .25rem .5rem; }
    .nav-link-picker__state.is-inactive { background: #f2f4f7; color: #667085; }
    .nav-toggle-row { display: flex; flex-wrap: wrap; gap: .55rem; }
    .nav-toggle { align-items: center; background: #f8faff; border: 1px solid #e4eaf5; border-radius: .55rem; color: #344054; cursor: pointer; display: inline-flex; font-size: .78rem; font-weight: 650; gap: .5rem; min-height: 2.65rem; padding: .55rem .75rem; }
    .nav-toggle input { height: 1rem; margin: 0; width: 1rem; }
    .nav-badge-panel, .nav-dropdown-panel { background: #fafbfc; border: 1px solid var(--nav-line); border-radius: .7rem; padding: .9rem; }
    .nav-dropdown-panel { display: grid; gap: .75rem; }
    .nav-dropdown-panel__head { align-items: center; display: flex; justify-content: space-between; }
    .nav-dropdown-panel__head h3 { color: #344054; font-size: .85rem; margin: 0; }
    .nav-group { background: #fff; border: 1px solid var(--nav-line); border-radius: .65rem; padding: .8rem; }
    .nav-group__head { align-items: flex-end; display: grid; gap: .65rem; grid-template-columns: 1fr 160px auto; }
    .nav-child-list { display: grid; gap: .55rem; margin-top: .75rem; }
    .nav-child-list > .nav-btn { justify-self: start; }
    .nav-media { align-items: stretch; display: grid; gap: .6rem; grid-template-columns: 6rem minmax(0, 1fr); }
    .nav-media__preview { align-items: center; aspect-ratio: 1; background: #f2f4f7; border: 1px solid var(--nav-line); border-radius: .6rem; color: var(--nav-muted); display: flex; justify-content: center; overflow: hidden; }
    .nav-media__preview img { height: 100%; object-fit: cover; width: 100%; }
    .nav-media__actions { align-content: start; display: grid; gap: .5rem; }
    .nav-media__actions .nav-btn { justify-self: start; }
    .nav-child { align-items: end; background: #f8faff; border-radius: .55rem; display: grid; gap: .55rem; grid-template-columns: minmax(110px, .7fr) minmax(0, 1.3fr) auto; padding: .65rem; }
    .nav-btn { align-items: center; border: 1px solid #d0d5dd; border-radius: .5rem; display: inline-flex; font-size: .78rem; font-weight: 700; gap: .35rem; justify-content: center; min-height: 2.5rem; padding: .5rem .75rem; }
    .nav-btn--soft { background: #fff; color: #344054; }
    .nav-btn--primary { background: var(--nav-blue); border-color: var(--nav-blue); color: #fff; }
    .nav-btn--danger { background: #fff; color: #b42318; }
    .nav-workspace__footer { align-items: center; background: rgba(255,255,255,.94); border-top: 1px solid var(--nav-line); bottom: 0; display: flex; gap: .65rem; justify-content: flex-end; margin: 1rem -1rem -1rem; padding: .9rem 1rem; position: sticky; z-index: 4; }
    .nav-preview { align-self: start; position: sticky; top: 5.5rem; }
    .nav-preview__browser { background: #fff; border: 1px solid var(--nav-line); border-radius: .875rem; box-shadow: 0 12px 30px rgba(16,24,40,.08); overflow: hidden; }
    .nav-preview__chrome { align-items: center; background: #f2f4f7; border-bottom: 1px solid var(--nav-line); display: flex; gap: .35rem; height: 2.25rem; padding: 0 .75rem; }
    .nav-preview__chrome i { background: #98a2b3; border-radius: 50%; height: .42rem; opacity: .55; width: .42rem; }
    .nav-preview__store { min-height: 24rem; padding: 1.25rem; }
    .nav-preview__logo { color: var(--nav-ink); font-size: 1rem; font-weight: 900; letter-spacing: .08em; margin-bottom: 1.5rem; }
    .nav-preview__menu { display: flex; flex-wrap: wrap; gap: .5rem; }
    .nav-preview__item { position: relative; }
    .nav-preview__trigger { align-items: center; background: #fff; border: 1px solid #e4e7ec; border-radius: .45rem; color: #344054; display: inline-flex; font-size: .75rem; gap: .35rem; min-height: 2.25rem; padding: .45rem .6rem; }
    .nav-preview__badge { border-radius: 999px; font-size: .55rem; font-weight: 800; padding: .15rem .3rem; }
    .nav-preview__dropdown { background: #f8faff; border: 1px solid #dfe5ef; border-radius: .55rem; display: grid; gap: .65rem; margin-top: .4rem; padding: .65rem; }
    .nav-preview__group strong { color: #344054; display: block; font-size: .68rem; margin-bottom: .3rem; }
    .nav-preview__group span { color: var(--nav-muted); display: block; font-size: .64rem; line-height: 1.7; }
    .nav-preview__help { color: var(--nav-muted); font-size: .75rem; line-height: 1.55; margin: .9rem 0 0; }
    @media (max-width: 1199.98px) { .nav-workspace { grid-template-columns: minmax(0, 1fr) 300px; } }
    @media (max-width: 991.98px) { .nav-workspace { grid-template-columns: 1fr; } .nav-preview { position: static; } }
    @media (max-width: 767.98px) { .nav-form-grid, .nav-link-fields, .nav-link-picker__type, .nav-group__head, .nav-child { grid-template-columns: 1fr; } .nav-form-grid .span-2, .nav-link-hint { grid-column: auto; } .nav-link-picker__selected, .nav-link-picker__selected.is-missing { grid-template-columns: 3rem minmax(0, 1fr); } .nav-link-picker__actions { grid-column: 1 / -1; } .nav-link-picker__actions button { flex: 1; } .nav-workspace__head { align-items: stretch; flex-direction: column; } .nav-workspace__status { align-self: flex-start; } }
    @media (prefers-reduced-motion: reduce) { .nav-workspace * { scroll-behavior: auto !important; transition: none !important; } }
  </style>
@endpush

@section('content')
  <template id="navigation-link-picker-template">
    <div class="nav-link-picker">
      <div class="nav-link-picker__type">
        <select :value="link.type" @change="changeType($event.target.value)" aria-label="{{ __('admin/design_navigation.link_type') }}">
          <option v-for="type in linkTypes" :key="type.value" :value="type.value">@{{ type.label }}</option>
        </select>
        <div v-if="link.type === 'custom'" class="nav-link-picker__direct">
          <input :value="link.value" @input="setValue($event.target.value)" type="text" inputmode="url" maxlength="2048" placeholder="{{ __('admin/design_navigation.custom_url_placeholder') }}" aria-label="{{ __('admin/design_navigation.custom_url') }}">
          <small class="nav-link-hint">{{ __('admin/design_navigation.custom_url_hint') }}</small>
        </div>
        <div v-else-if="link.type === 'static'" class="nav-link-picker__direct">
          <select :value="link.value" @change="setValue($event.target.value)" aria-label="{{ __('admin/design_navigation.system_page') }}">
            <option value="">{{ __('admin/design_navigation.choose_system_page') }}</option>
            <option v-for="route in staticRoutes" :key="route.value" :value="route.value">@{{ route.label }}</option>
          </select>
        </div>
        <div v-else></div>
      </div>

      <template v-if="isResource">
        <div v-if="selectedResource" class="nav-link-picker__selected">
          <span class="nav-link-picker__thumb" aria-hidden="true"><img v-if="selectedResource.image" :src="selectedResource.image" alt=""><i v-else class="bi" :class="resourceIcon"></i></span>
          <span class="nav-link-picker__copy"><strong>@{{ selectedResource.name }}</strong><small>@{{ currentTypeLabel }}<template v-if="selectedResource.meta"> · @{{ selectedResource.meta }}</template></small></span>
          <span class="nav-link-picker__actions">
            <button type="button" @click="beginChange">{{ __('admin/design_navigation.change_selection') }}</button>
            <button type="button" @click="clearSelection">{{ __('admin/design_navigation.clear_selection') }}</button>
          </span>
        </div>
        <div v-else-if="link.value && !loading" class="nav-link-picker__selected is-missing" role="alert">
          <span class="nav-link-picker__thumb" aria-hidden="true"><i class="bi bi-exclamation-triangle"></i></span>
          <span class="nav-link-picker__copy"><strong>{{ __('admin/design_navigation.resource_missing') }}</strong><small>{{ __('admin/design_navigation.resource_missing_help') }}</small></span>
          <span class="nav-link-picker__actions"><button type="button" @click="beginChange">{{ __('admin/design_navigation.choose_again') }}</button><button type="button" @click="clearSelection">{{ __('admin/design_navigation.clear_selection') }}</button></span>
        </div>

        <div v-if="editing || !link.value" class="nav-link-picker__search" @keydown.esc.stop="closeSearch">
          <div class="nav-link-picker__input">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input :id="inputId" v-model.trim="query" type="search" maxlength="120" autocomplete="off"
              :placeholder="searchPlaceholder" :aria-label="searchPlaceholder" :aria-controls="resultsId"
              @input="queueSearch" @focus="ensureResults" @keyup.enter.prevent="searchNow">
            <button type="button" @click="searchNow">{{ __('admin/design_navigation.search') }}</button>
          </div>
          <span class="nav-link-picker__status" :class="{ 'is-error': error }" role="status" aria-live="polite">@{{ pickerStatus }}</span>
          <div v-if="results.length" :id="resultsId" class="nav-link-picker__results" role="listbox" :aria-label="searchPlaceholder">
            <button v-for="resource in results" :key="resource.id" type="button" class="nav-link-picker__result" role="option"
              :disabled="!resource.status" :aria-selected="String(String(link.value) === String(resource.id))" @click="chooseResource(resource)">
              <span class="nav-link-picker__thumb" aria-hidden="true"><img v-if="resource.image" :src="resource.image" alt=""><i v-else class="bi" :class="resourceIcon"></i></span>
              <span class="nav-link-picker__copy"><strong>@{{ resource.name }}</strong><small v-if="resource.meta">@{{ resource.meta }}</small></span>
              <span class="nav-link-picker__state" :class="{ 'is-inactive': !resource.status }">@{{ resource.status ? activeLabel : inactiveLabel }}</span>
            </button>
          </div>
        </div>
      </template>
    </div>
  </template>

  <div id="navigation-app" v-cloak>
    <div class="nav-workspace__head">
      <div>
        <h2>{{ __('admin/design_navigation.title') }}</h2>
        <p>{{ __('admin/design_navigation.subtitle') }}</p>
      </div>
      <span class="nav-workspace__status" :class="statusClass" role="status" aria-live="polite">
        <i class="bi" :class="saving ? 'bi-arrow-repeat' : (dirty ? 'bi-cloud-slash' : 'bi-cloud-check')" aria-hidden="true"></i>
        @{{ statusText }}
      </span>
    </div>

    <div v-if="message" class="nav-workspace__message" :class="messageType === 'error' ? 'alert-danger' : 'alert-success'" role="alert">@{{ message }}</div>

    <div class="nav-workspace">
      <section class="card" aria-labelledby="navigation-items-title">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
            <h3 class="h5 mb-0" id="navigation-items-title">{{ __('admin/design_navigation.items') }}</h3>
            <button class="nav-btn nav-btn--primary" type="button" @click="addMenu"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('admin/design_navigation.add_item') }}</button>
          </div>

          <div v-if="!menus.length" class="nav-builder-empty"><i class="bi bi-menu-button-wide fs-3 d-block mb-2" aria-hidden="true"></i>{{ __('admin/design_navigation.empty') }}</div>
          <div v-else class="nav-builder-list">
            <article v-for="(item, index) in menus" :key="item._key" class="nav-builder-card">
              <header class="nav-builder-card__head">
                <span class="nav-builder-card__index">@{{ index + 1 }}</span>
                <strong>@{{ localized(item.name) || ('Menu ' + (index + 1)) }}</strong>
                <div class="nav-builder-card__actions">
                  <button type="button" :disabled="index === 0" @click="move(menus, index, -1)" title="{{ __('admin/design_navigation.move_up') }}" aria-label="{{ __('admin/design_navigation.move_up') }}"><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
                  <button type="button" :disabled="index === menus.length - 1" @click="move(menus, index, 1)" title="{{ __('admin/design_navigation.move_down') }}" aria-label="{{ __('admin/design_navigation.move_down') }}"><i class="bi bi-arrow-down" aria-hidden="true"></i></button>
                  <button type="button" class="is-danger" @click="removeMenu(index)" title="{{ __('admin/design_navigation.remove') }}" aria-label="{{ __('admin/design_navigation.remove') }}"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                </div>
              </header>
              <div class="nav-builder-card__body">
                <div class="nav-form-grid">
                  <label class="nav-field"><span>{{ __('admin/design_navigation.item_name') }}</span><input type="text" maxlength="120" v-model.trim="item.name[locale]" required></label>
                  <div class="nav-field"><span>{{ __('admin/design_navigation.destination') }}</span>
                    <navigation-link-picker :link="item.link" :link-types="linkTypes" :static-routes="staticRoutes" :resources-url="resourceSearchUrl"></navigation-link-picker>
                  </div>
                </div>

                <div class="nav-toggle-row">
                  <label class="nav-toggle"><input type="checkbox" v-model="item.link.new_window">{{ __('admin/design_navigation.new_window') }}</label>
                  <label class="nav-toggle"><input type="checkbox" v-model="item.badge.isShow">{{ __('admin/design_navigation.badge') }}</label>
                  <label class="nav-toggle"><input type="checkbox" v-model="item.isChildren">{{ __('admin/design_navigation.dropdown') }}</label>
                  <label v-if="item.isChildren" class="nav-toggle"><input type="checkbox" v-model="item.isFull">{{ __('admin/design_navigation.full_width') }}</label>
                </div>

                <div v-if="item.badge.isShow" class="nav-badge-panel nav-form-grid">
                  <label class="nav-field"><span>{{ __('admin/design_navigation.badge_name') }}</span><input type="text" maxlength="40" v-model.trim="item.badge.name[locale]"></label>
                  <label class="nav-field"><span>{{ __('admin/design_navigation.badge_background') }}</span><input type="color" v-model="item.badge.bg_color"></label>
                  <label class="nav-field"><span>{{ __('admin/design_navigation.badge_text') }}</span><input type="color" v-model="item.badge.text_color"></label>
                </div>

                <section v-if="item.isChildren" class="nav-dropdown-panel" :aria-label="'{{ __('admin/design_navigation.groups') }} ' + (index + 1)">
                  <div class="nav-dropdown-panel__head"><h3>{{ __('admin/design_navigation.groups') }}</h3><button class="nav-btn nav-btn--soft" type="button" @click="addGroup(item)"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('admin/design_navigation.add_group') }}</button></div>
                  <article v-for="(group, groupIndex) in item.childrenGroup" :key="group.id" class="nav-group">
                    <div class="nav-group__head">
                      <label class="nav-field"><span>{{ __('admin/design_navigation.group_name') }}</span><input type="text" maxlength="120" v-model.trim="group.name[locale]"></label>
                      <label class="nav-field"><span>{{ __('admin/design_navigation.group_type') }}</span><select v-model="group.type"><option value="link">{{ __('admin/design_navigation.group_links') }}</option><option value="image">{{ __('admin/design_navigation.group_image') }}</option></select></label>
                      <div class="nav-builder-card__actions">
                        <button type="button" :disabled="groupIndex === 0" @click="move(item.childrenGroup, groupIndex, -1)" aria-label="{{ __('admin/design_navigation.move_up') }}"><i class="bi bi-arrow-up"></i></button>
                        <button type="button" :disabled="groupIndex === item.childrenGroup.length - 1" @click="move(item.childrenGroup, groupIndex, 1)" aria-label="{{ __('admin/design_navigation.move_down') }}"><i class="bi bi-arrow-down"></i></button>
                        <button type="button" class="is-danger" @click="item.childrenGroup.splice(groupIndex, 1)" aria-label="{{ __('admin/design_navigation.remove') }}"><i class="bi bi-trash3"></i></button>
                      </div>
                    </div>

                    <div v-if="group.type === 'image'" class="nav-form-grid mt-3">
                      <div class="nav-field">
                        <span>{{ __('admin/design_navigation.group_image') }}</span>
                        <div class="nav-media">
                          <div class="nav-media__preview">
                            <img v-if="group.image.image[locale]" :src="thumbnail(group.image.image[locale])" alt="">
                            <i v-else class="bi bi-image" aria-hidden="true"></i>
                          </div>
                          <div class="nav-media__actions">
                            <input type="text" maxlength="2048" v-model.trim="group.image.image[locale]" placeholder="image/catalog/...">
                            <button class="nav-btn nav-btn--soft" type="button" @click="selectImage(group)"><i class="bi bi-images" aria-hidden="true"></i>{{ __('admin/design_navigation.choose_image') }}</button>
                            <button v-if="group.image.image[locale]" class="nav-btn nav-btn--danger" type="button" @click="removeImage(group)">{{ __('admin/design_navigation.remove_image') }}</button>
                          </div>
                        </div>
                      </div>
                      <div class="nav-field"><span>{{ __('admin/design_navigation.destination') }}</span><navigation-link-picker :link="group.image.link" :link-types="linkTypes" :static-routes="staticRoutes" :resources-url="resourceSearchUrl"></navigation-link-picker></div>
                    </div>

                    <div v-else class="nav-child-list">
                      <div v-for="(child, childIndex) in group.children" :key="child._key" class="nav-child">
                        <label class="nav-field"><span>{{ __('admin/design_navigation.child_name') }}</span><input type="text" maxlength="120" v-model.trim="child.name[locale]"></label>
                        <div class="nav-field"><span>{{ __('admin/design_navigation.destination') }}</span><navigation-link-picker :link="child.link" :link-types="linkTypes" :static-routes="staticRoutes" :resources-url="resourceSearchUrl"></navigation-link-picker></div>
                        <div class="nav-builder-card__actions"><button type="button" :disabled="childIndex === 0" @click="move(group.children, childIndex, -1)" aria-label="{{ __('admin/design_navigation.move_up') }}"><i class="bi bi-arrow-up"></i></button><button type="button" :disabled="childIndex === group.children.length - 1" @click="move(group.children, childIndex, 1)" aria-label="{{ __('admin/design_navigation.move_down') }}"><i class="bi bi-arrow-down"></i></button><button type="button" class="is-danger" @click="group.children.splice(childIndex, 1)" aria-label="{{ __('admin/design_navigation.remove') }}"><i class="bi bi-trash3"></i></button></div>
                      </div>
                      <button class="nav-btn nav-btn--soft justify-self-start" type="button" @click="addChild(group)"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('admin/design_navigation.add_child') }}</button>
                    </div>
                  </article>
                </section>
              </div>
            </article>
          </div>

          <div class="nav-workspace__footer">
            <a class="nav-btn nav-btn--soft me-auto" href="{{ $storefront_preview_url }}" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>{{ __('admin/design_navigation.open_storefront') }}</a>
            <button class="nav-btn nav-btn--soft" type="button" :disabled="saving" @click="save(false)">{{ __('admin/design_navigation.save_draft') }}</button>
            <button class="nav-btn nav-btn--primary" type="button" :disabled="saving" @click="save(true)">{{ __('admin/design_navigation.publish') }}</button>
          </div>
        </div>
      </section>

      <aside class="nav-preview" aria-labelledby="navigation-preview-title">
        <div class="nav-preview__browser">
          <div class="nav-preview__chrome" aria-hidden="true"><i></i><i></i><i></i></div>
          <div class="nav-preview__store">
            <div class="nav-preview__logo">{{ strtoupper(site_brand_name()) }}</div>
            <h3 class="h6" id="navigation-preview-title">{{ __('admin/design_navigation.preview') }}</h3>
            <div class="nav-preview__menu">
              <div v-for="item in menus" :key="'preview-' + item._key" class="nav-preview__item">
                <span class="nav-preview__trigger">@{{ localized(item.name) || 'Menu' }}<span v-if="item.badge.isShow && localized(item.badge.name)" class="nav-preview__badge" :style="{ backgroundColor: item.badge.bg_color, color: item.badge.text_color }">@{{ localized(item.badge.name) }}</span><i v-if="item.isChildren" class="bi bi-chevron-down" aria-hidden="true"></i></span>
                <div v-if="item.isChildren && item.childrenGroup.length" class="nav-preview__dropdown">
                  <div v-for="group in item.childrenGroup" :key="'preview-' + group.id" class="nav-preview__group"><strong>@{{ localized(group.name) || (group.type === 'image' ? 'Image' : 'Group') }}</strong><span v-if="group.type === 'image'">Image card</span><span v-else v-for="child in group.children">@{{ localized(child.name) || 'Link' }}</span></div>
                </div>
              </div>
            </div>
            <p class="nav-preview__help">{{ __('admin/design_navigation.preview_help') }}</p>
          </div>
        </div>
      </aside>
    </div>
  </div>
@endsection

@push('footer')
  <script>
    Vue.component('navigation-link-picker', {
      template: '#navigation-link-picker-template',
      props: {
        link: { type: Object, required: true },
        linkTypes: { type: Array, required: true },
        staticRoutes: { type: Array, required: true },
        resourcesUrl: { type: String, required: true }
      },
      data: function () {
        return {
          query: '',
          results: [],
          selectedResource: null,
          loading: false,
          error: '',
          editing: !this.link.value,
          searchTimer: null,
          requestController: null,
          requestSequence: 0,
          loadingSequence: 0
        };
      },
      computed: {
        isResource: function () { return ['product', 'category', 'page', 'brand'].includes(this.link.type); },
        currentTypeLabel: function () { const option = this.linkTypes.find(type => type.value === this.link.type); return option ? option.label : ''; },
        inputId: function () { return 'navigation-resource-search-' + this._uid; },
        resultsId: function () { return this.inputId + '-results'; },
        resourceIcon: function () { return { product: 'bi-box-seam', category: 'bi-grid', page: 'bi-file-earmark-text', brand: 'bi-award' }[this.link.type] || 'bi-link-45deg'; },
        searchPlaceholder: function () { return @json(__('admin/design_navigation.search_placeholder')).replace(':type', this.currentTypeLabel); },
        activeLabel: function () { return @json(__('admin/design_navigation.active')); },
        inactiveLabel: function () { return @json(__('admin/design_navigation.inactive')); },
        pickerStatus: function () {
          if (this.loading) return @json(__('admin/design_navigation.searching'));
          if (this.error) return this.error;
          if (this.results.length) return @json(__('admin/design_navigation.results_count')).replace(':count', String(this.results.length));
          if (this.query) return @json(__('admin/design_navigation.no_results'));
          return @json(__('admin/design_navigation.search_help'));
        }
      },
      watch: {
        'link.type': function () {
          this.cancelRequest();
          this.query = '';
          this.results = [];
          this.error = '';
          this.selectedResource = null;
          this.editing = !this.link.value;
          if (this.isResource && this.link.value) this.resolveSelection();
        },
        'link.value': {
          immediate: true,
          handler: function (value) {
            if (!this.isResource || !value) {
              this.selectedResource = null;
              this.editing = !value;
              return;
            }
            if (this.selectedResource && String(this.selectedResource.id) === String(value)) return;
            this.resolveSelection();
          }
        }
      },
      beforeDestroy: function () {
        if (this.searchTimer) window.clearTimeout(this.searchTimer);
        this.cancelRequest();
      },
      methods: {
        setValue: function (value) { this.$set(this.link, 'value', String(value || '').trim()); },
        changeType: function (type) {
          this.cancelRequest();
          this.loadingSequence += 1;
          this.loading = false;
          this.$set(this.link, 'type', type);
          this.$set(this.link, 'value', '');
          this.query = '';
          this.results = [];
          this.selectedResource = null;
          this.error = '';
          this.editing = true;
        },
        beginChange: function () { this.editing = true; this.$nextTick(this.ensureResults); },
        closeSearch: function () { if (this.selectedResource) this.editing = false; },
        clearSelection: function () {
          this.cancelRequest();
          this.loadingSequence += 1;
          this.loading = false;
          this.$set(this.link, 'value', '');
          this.selectedResource = null;
          this.query = '';
          this.results = [];
          this.error = '';
          this.editing = true;
          this.$nextTick(this.ensureResults);
        },
        chooseResource: function (resource) {
          if (!resource || !resource.status) return;
          this.cancelRequest();
          this.loadingSequence += 1;
          this.loading = false;
          this.selectedResource = resource;
          this.$set(this.link, 'value', String(resource.id));
          this.query = '';
          this.results = [];
          this.error = '';
          this.editing = false;
        },
        queueSearch: function () {
          if (this.searchTimer) window.clearTimeout(this.searchTimer);
          this.searchTimer = window.setTimeout(() => this.searchNow(), 280);
        },
        ensureResults: function () { if (!this.loading && !this.results.length) this.searchNow(); },
        cancelRequest: function () {
          if (this.requestController && typeof this.requestController.abort === 'function') this.requestController.abort();
          this.requestController = null;
        },
        requestResources: async function (parameters) {
          this.cancelRequest();
          const sequence = ++this.requestSequence;
          const controller = typeof AbortController === 'function' ? new AbortController() : null;
          this.requestController = controller;
          const url = new URL(this.resourcesUrl, window.location.origin);
          url.searchParams.set('type', this.link.type);
          Object.entries(parameters).forEach(([key, value]) => url.searchParams.set(key, String(value)));
          const response = await fetch(url.toString(), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            ...(controller ? { signal: controller.signal } : {})
          });
          const payload = await response.json().catch(() => ({}));
          if (sequence !== this.requestSequence) return null;
          if (!response.ok || payload.status !== 'success') throw new Error(payload.message || @json(__('admin/design_navigation.resource_error')));
          return Array.isArray(payload.data?.items) ? payload.data.items : [];
        },
        searchNow: async function () {
          if (!this.isResource) return;
          if (this.searchTimer) window.clearTimeout(this.searchTimer);
          const loadingSequence = ++this.loadingSequence;
          this.loading = true;
          this.error = '';
          try {
            const items = await this.requestResources({ query: this.query });
            if (items) this.results = items;
          } catch (error) {
            if (error && error.name === 'AbortError') return;
            this.results = [];
            this.error = error instanceof Error ? error.message : @json(__('admin/design_navigation.resource_error'));
          } finally {
            if (loadingSequence === this.loadingSequence) this.loading = false;
          }
        },
        resolveSelection: async function () {
          if (!this.isResource || !/^\d+$/.test(String(this.link.value || ''))) {
            this.selectedResource = null;
            this.editing = true;
            return;
          }
          const loadingSequence = ++this.loadingSequence;
          this.loading = true;
          this.error = '';
          try {
            const items = await this.requestResources({ id: this.link.value });
            this.selectedResource = items && items.length ? items[0] : null;
            this.editing = !this.selectedResource;
          } catch (error) {
            if (error && error.name === 'AbortError') return;
            this.selectedResource = null;
            this.editing = true;
            this.error = error instanceof Error ? error.message : @json(__('admin/design_navigation.resource_error'));
          } finally {
            if (loadingSequence === this.loadingSequence) this.loading = false;
          }
        }
      }
    });

    new Vue({
      el: '#navigation-app',
      data: function () {
        return {
          locale: @json(locale()),
          menus: @json($menu_settings['menus'] ?? []),
          linkTypes: @json($link_types),
          staticRoutes: @json($static_routes),
          resourceSearchUrl: @json($resource_search_url),
          draftVersion: @json((int) $draft_version),
          dirty: @json((bool) $has_unpublished_changes),
          localDirty: false,
          editRevision: 0,
          saving: false,
          message: '',
          messageType: 'success',
          ready: false,
          keySeed: Date.now()
        };
      },
      computed: {
        statusText: function () { return this.saving ? @json(__('admin/design_navigation.saving')) : (this.dirty ? @json(__('admin/design_navigation.unpublished')) : @json(__('admin/design_navigation.published_state'))); },
        statusClass: function () { return { 'is-saving': this.saving, 'is-dirty': !this.saving && this.dirty, 'is-success': !this.saving && !this.dirty }; }
      },
      watch: {
        menus: { deep: true, handler: function () { if (this.ready) { this.editRevision += 1; this.localDirty = true; this.dirty = true; this.message = ''; } } }
      },
      created: function () {
        this.menus.forEach(this.hydrateMenu);
        this.$nextTick(() => { this.ready = true; });
      },
      mounted: function () { window.addEventListener('beforeunload', this.warnUnsaved); },
      beforeDestroy: function () { window.removeEventListener('beforeunload', this.warnUnsaved); },
      methods: {
        warnUnsaved: function (event) {
          if (!this.localDirty && !this.saving) return;
          event.preventDefault();
          event.returnValue = '';
        },
        uid: function (prefix) { this.keySeed += 1; return prefix + '_' + this.keySeed.toString(36); },
        localized: function (value) { if (!value || typeof value !== 'object') return ''; return value[this.locale] || value.zh_cn || value.en || Object.values(value)[0] || ''; },
        localizedMap: function (value, fallback) { const map = value && typeof value === 'object' && !Array.isArray(value) ? value : {}; if (typeof map[this.locale] !== 'string') this.$set(map, this.locale, this.localized(map) || fallback || ''); return map; },
        makeLink: function () { return { type: 'custom', value: '', text: {}, new_window: false }; },
        hydrateLink: function (link) { link = link && typeof link === 'object' ? link : this.makeLink(); if (!link.type) this.$set(link, 'type', 'custom'); if (typeof link.value !== 'string') this.$set(link, 'value', String(link.value || '')); if (!link.text || typeof link.text !== 'object') this.$set(link, 'text', {}); if (typeof link.new_window !== 'boolean') this.$set(link, 'new_window', false); return link; },
        hydrateMenu: function (item) {
          if (!item._key) this.$set(item, '_key', this.uid('menu'));
          this.$set(item, 'name', this.localizedMap(item.name, ''));
          this.$set(item, 'link', this.hydrateLink(item.link));
          const badge = item.badge && typeof item.badge === 'object' ? item.badge : {};
          if (typeof badge.isShow !== 'boolean') this.$set(badge, 'isShow', false);
          this.$set(badge, 'name', this.localizedMap(badge.name, ''));
          if (!badge.bg_color) this.$set(badge, 'bg_color', '#FD560F');
          if (!badge.text_color) this.$set(badge, 'text_color', '#FFFFFF');
          this.$set(item, 'badge', badge);
          if (typeof item.isChildren !== 'boolean') this.$set(item, 'isChildren', false);
          if (typeof item.isFull !== 'boolean') this.$set(item, 'isFull', false);
          if (!Array.isArray(item.childrenGroup)) this.$set(item, 'childrenGroup', []);
          item.childrenGroup.forEach(this.hydrateGroup);
        },
        hydrateGroup: function (group) {
          if (!group.id) this.$set(group, 'id', this.uid('group'));
          this.$set(group, 'name', this.localizedMap(group.name, ''));
          if (group.type !== 'image') this.$set(group, 'type', 'link');
          const image = group.image && typeof group.image === 'object' ? group.image : {};
          this.$set(image, 'image', this.localizedMap(image.image, ''));
          this.$set(image, 'link', this.hydrateLink(image.link));
          this.$set(group, 'image', image);
          if (!Array.isArray(group.children)) this.$set(group, 'children', []);
          group.children.forEach(this.hydrateChild);
        },
        hydrateChild: function (child) { if (!child._key) this.$set(child, '_key', this.uid('child')); this.$set(child, 'name', this.localizedMap(child.name, '')); this.$set(child, 'link', this.hydrateLink(child.link)); },
        addMenu: function () { const item = { isFull: false, badge: { isShow: false, name: {}, bg_color: '#FD560F', text_color: '#FFFFFF' }, link: this.makeLink(), name: {}, isChildren: false, childrenGroup: [] }; this.hydrateMenu(item); item.name[this.locale] = 'New menu'; this.menus.push(item); },
        addGroup: function (item) { const group = { id: this.uid('group'), name: {}, type: 'link', image: { image: {}, link: this.makeLink() }, children: [] }; this.hydrateGroup(group); item.childrenGroup.push(group); },
        addChild: function (group) { const child = { name: {}, link: this.makeLink() }; this.hydrateChild(child); group.children.push(child); },
        selectImage: function (group) {
          if (!window.bk || typeof window.bk.fileManagerIframe !== 'function') return;
          window.bk.fileManagerIframe(files => {
            const selected = window.LongosFileManager && window.LongosFileManager.normalizeMediaFiles(files, 'image')[0];
            if (selected) this.$set(group.image.image, this.locale, selected.path);
          }, { mime: 'image', is_multiple: 0 });
        },
        removeImage: function (group) { this.$set(group.image.image, this.locale, ''); },
        removeMenu: function (index) { if (window.confirm(@json(__('admin/design_navigation.remove')) + '?')) this.menus.splice(index, 1); },
        move: function (items, index, direction) { const target = index + direction; if (target < 0 || target >= items.length) return; const item = items.splice(index, 1)[0]; items.splice(target, 0, item); },
        cleanPayload: function () { return JSON.parse(JSON.stringify({ menus: this.menus }, (key, value) => key === '_key' ? undefined : value)); },
        save: async function (publish) {
          if (this.saving) return;
          this.saving = true; this.message = '';
          const submittedRevision = this.editRevision;
          const controller = typeof AbortController === 'function' ? new AbortController() : null;
          const timeout = window.setTimeout(() => { if (controller) controller.abort(); }, 30000);
          try {
            const response = await fetch(@json(admin_route('design.navigation.update')), {
              method: 'PUT', credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()), 'X-Builder-Draft-Version': String(this.draftVersion) },
              body: JSON.stringify({ menu: this.cleanPayload(), publish: publish }),
              ...(controller ? { signal: controller.signal } : {})
            });
            const result = await response.json().catch(() => ({}));
            if (response.status === 401 || response.status === 419) throw new Error(@json(__('admin/design_navigation.session_expired')));
            if (!response.ok || result.status !== 'success') throw new Error(result.message || 'Save failed (' + response.status + ')');
            const nextVersion = result.data && result.data.draft_version;
            if (!result.data || !Number.isSafeInteger(nextVersion) || nextVersion < 1 || typeof result.data.has_unpublished_changes !== 'boolean') throw new Error(@json(__('admin/design_navigation.invalid_response')));
            this.draftVersion = nextVersion;
            if (submittedRevision === this.editRevision) {
              this.localDirty = false;
              this.dirty = result.data.has_unpublished_changes;
              this.message = result.message;
            } else {
              this.localDirty = true;
              this.dirty = true;
              this.message = @json(__('admin/design_navigation.newer_edits_pending'));
            }
            this.messageType = 'success';
          } catch (error) {
            this.message = error && error.name === 'AbortError' ? @json(__('admin/design_navigation.save_timeout')) : (error instanceof Error ? error.message : @json(__('admin/design_navigation.save_failed')));
            this.messageType = 'error';
          } finally { window.clearTimeout(timeout); this.saving = false; }
        }
      }
    });
  </script>
@endpush
