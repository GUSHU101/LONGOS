/*
 * @copyright     2022 beikeshop.com - All Rights Reserved.
 * @link          https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @Date          2022-08-19 18:21:32
 * @LastEditTime  2023-11-30 11:12:53
 */

require('./product-quantity');

$(document).on('click', '.quantity-wrap .btn', function(event) {
  // Product-detail quantity is owned by its inventory-aware Vue component.
  // If Vue is unavailable, the page template supplies one bounded fallback.
  // Keeping this generic handler away from that stepper prevents duplicate
  // increments while preserving the existing cart-page quantity controls.
  if ($(this).closest('[data-product-quantity-stepper]').length) {
    return;
  }

  event.stopPropagation();
  event.preventDefault();

  let input = $(this).siblings('input')

  if ($(this).hasClass('quantity-increase')) {
    input.val(input.val() * 1 + 1)
    input.get(0).dispatchEvent(new Event('input'));
    return;
  }

  if (input.val() * 1 <= input.attr('minimum') * 1) {
    return;
  }

  if (input.val () * 1 <= 1) {
    return;
  }

  input.val(input.val() * 1 - 1)
  input.get(0).dispatchEvent(new Event('input'));
});

$(document).on('click','.login-before-show-price',function () {
  bk.openLogin()
})
