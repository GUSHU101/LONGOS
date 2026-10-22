/*
 * @copyright     2022 beikeshop.com - All Rights Reserved.
 * @link          https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @Date          2022-08-29 17:32:51
 * @LastEditTime: 2025-01-03 21:58:41
 */

import http from "../../../../js/http";
import common from "./common";
window.bk = common;
window.$http = http;

const token = document.querySelector('meta[name="csrf-token"]').content;
const base = document.querySelector('base').href;
http.axiosApi.defaults.headers.common['X-CSRF-TOKEN'] = token;
http.axiosApi.defaults.headers.common['Accept'] = 'application/json';

import './product';
import './header'
import './footer'
import './order-tracking'
import './order-actions'
import './campaign-offer'
import './bootstrap-validation'

$(document).ready(function ($) {
  const analyticsUrl = window.longosStorefrontRoutes?.analyticsVisit;
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  let analyticsSent = false;
  let engagementTimer = null;

  const confirmHumanVisit = (activity) => {
    if (analyticsSent || !analyticsUrl || !csrfToken || document.visibilityState !== 'visible' || navigator.webdriver) {
      return;
    }

    analyticsSent = true;
    if (engagementTimer) window.clearTimeout(engagementTimer);

    const payload = new FormData();
    payload.append('_token', csrfToken);
    payload.append('activity', activity);

    if (navigator.sendBeacon && navigator.sendBeacon(analyticsUrl, payload)) {
      return;
    }

    fetch(analyticsUrl, {
      method: 'POST',
      body: payload,
      credentials: 'same-origin',
      keepalive: true,
      headers: {'Accept': 'application/json'},
    }).catch(() => {});
  };

  [
    ['pointerdown', 'pointer'],
    ['keydown', 'keyboard'],
    ['touchstart', 'touch'],
    ['scroll', 'scroll'],
  ].forEach(([eventName, activity]) => {
    window.addEventListener(eventName, () => confirmHumanVisit(activity), {once: true, passive: true});
  });

  engagementTimer = window.setTimeout(() => {
    if (document.hasFocus()) confirmHumanVisit('engaged');
  }, 15000);

  if ($(window).width() > 992 && $('.x-fixed-top').length) {
    $('.x-fixed-top').scrollToFixed({
      zIndex: 99,
      marginTop: $('.header-content').outerHeight(true) - 18 || 0,
      limit: function () {
        var limit = $('footer').offset().top - 84 - $('.x-fixed-top').outerHeight(true);
        return limit
      }
    });
  }

  const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]')
  const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl))

  bk.productImageResize()
  window.addEventListener('resize', function () {
    bk.productImageResize()
  })
});

bk.getCarts(); // 页面初始加载购物车数据
