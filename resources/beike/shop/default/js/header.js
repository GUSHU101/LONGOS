/*
 * @copyright     2022 beikeshop.com - All Rights Reserved.
 * @link          https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @Date          2022-08-16 18:47:18
 * @LastEditTime  2024-12-12 10:52:44
 */

$(function () {
  // 搜索弹出层交互
  const myOffcanvas = document.getElementById("offcanvas-search-top");
  if (myOffcanvas) {
    myOffcanvas.addEventListener("shown.bs.offcanvas", function () {
      $("#offcanvas-search-top input").focus();
    });

    // Bind once rather than once per offcanvas opening; otherwise every later
    // search can submit repeatedly after the panel has been used a few times.
    $("#offcanvas-search-top input").on("keydown", function (event) {
      if (event.key !== "Enter" || $(this).val() === "") return;
      const lang = $(this).data("lang");
      const keyword = encodeURIComponent($(this).val().trim());
      location.href = lang
        ? "/" + lang + "/products/search?keyword=" + keyword
        : "products/search?keyword=" + keyword;
    });
  }

  // 购物车侧边栏弹出
  $(document).on("click", ".btn-right-cart", function () {
    if (!config.isLogin && !config.guestCheckout) {
      bk.openLogin()
      return;
    }

    const currentUrl = window.location.pathname.replace(/\/$/, '');
    const cartsUrl = window.longosStorefrontRoutes?.cartsIndex || 'carts';
    const checkoutUrl = window.longosStorefrontRoutes?.checkoutIndex || 'checkout';
    const protectedPaths = [new URL(cartsUrl, window.location.origin).pathname, new URL(checkoutUrl, window.location.origin).pathname]
      .map((path) => path.replace(/\/$/, ''));
    if (protectedPaths.includes(currentUrl)) {
      location.href = cartsUrl;
      return;
    }

    const offcanvasRightCart = new bootstrap.Offcanvas('#offcanvas-right-cart')
    offcanvasRightCart.show()
  });

  // 侧边栏购物车删除商品
  $(document).on('click', '.offcanvas-products-delete', function () {
    const id = $(this).data('id');

    $http.delete(`carts/${id}`).then((res) => {
      $(this).parents('.product-list').remove();
      if (!res.data.quantity) {
        $('.cart-badge-quantity').hide();
        $('.empty-cart').removeClass('d-none');
      } else {
        $('.cart-badge-quantity').show().html(res.data.quantity > 99 ? '99+' : res.data.quantity);
      }

      $('.offcanvas-right-cart-count').text(res.data.quantity);
      $('.offcanvas-right-cart-amount').text(res.data.amount_format);
    })
  })

  // Mobile navigation uses one persistent offcanvas instance so repeated
  // opening never stacks focus traps or backdrops.
  const mobileMenuDrawer = document.getElementById('offcanvas-mobile-menu');
  const mobileMenuTrigger = document.querySelector('.mobile-open-menu');

  if (mobileMenuDrawer && mobileMenuTrigger) {
    mobileMenuDrawer.addEventListener('shown.bs.offcanvas', () => {
      mobileMenuTrigger.setAttribute('aria-expanded', 'true');
    });

    mobileMenuDrawer.addEventListener('hidden.bs.offcanvas', () => {
      mobileMenuTrigger.setAttribute('aria-expanded', 'false');

      // Return the drawer to a predictable top-level state for its next use.
      mobileMenuDrawer.querySelectorAll('.collapse.show[data-bs-parent]').forEach((panel) => {
        const collapse = bootstrap.Collapse.getInstance(panel);
        if (collapse) {
          collapse.hide();
        } else {
          panel.classList.remove('show');
          mobileMenuDrawer.querySelectorAll(`[data-bs-target="#${panel.id}"]`).forEach((toggle) => {
            toggle.classList.add('collapsed');
            toggle.setAttribute('aria-expanded', 'false');
          });
        }
      });
    });

    $(document).on('click', '.mobile-open-menu', function () {
      bootstrap.Offcanvas.getOrCreateInstance(mobileMenuDrawer).show();
    });
  }

  // 右侧购物车弹出层内交互
  $(document).on("click", "#offcanvas-right-cart .product-list .select-wrap", function () {
    const [unchecked, checked] = ['bi bi-circle', 'bi bi-check-circle-fill'];
    const productListAll = $('#offcanvas-right-cart .product-list').length;
    const cartId = $(this).find('i.bi').data('id');

    const isChecked = $(this).children('i').hasClass(unchecked);
    $(this).children('i').prop('class', isChecked ? checked : unchecked);
    $(this).attr('aria-pressed', isChecked ? 'true' : 'false');

    const checkedProduct = $('#offcanvas-right-cart .offcanvas-right-products i.bi-check-circle-fill').length;

    const allSelected = productListAll == checkedProduct;
    $('.offcanvas-footer .all-select i').prop('class', allSelected ? checked : unchecked);
    $('.offcanvas-footer .all-select').attr('aria-pressed', allSelected ? 'true' : 'false');

    const checkedIds = $('#offcanvas-right-cart .product-list').map(function () {
      return $(this).find('i.bi-check-circle-fill').data('id');
    }).get();

    if (!checkedIds.length) {
      $('#offcanvas-right-cart .to-checkout').addClass('disabled')
    } else {
      $('#offcanvas-right-cart .to-checkout').removeClass('disabled')
    }

    const cartSelectionUrl = isChecked
      ? (window.longosStorefrontRoutes?.cartsSelect || 'carts/select')
      : (window.longosStorefrontRoutes?.cartsUnselect || 'carts/unselect');
    $http.post(cartSelectionUrl, {
      cart_ids: [cartId]
    }, {
      hload: true
    }).then((res) => {
      updateMiniCartData(res);
    })
  });

  $(document).on("click", "#offcanvas-right-cart .all-select", function () {
    const [unchecked, checked] = ['bi bi-circle', 'bi bi-check-circle-fill'];

    const checkedIds = $('#offcanvas-right-cart .product-list').map(function () {
      return $(this).find('i.bi').data('id');
    }).get();

    const isChecked = $(this).children('i').hasClass(unchecked);
    $(this).children('i').prop('class', isChecked ? checked : unchecked);
    $(this).attr('aria-pressed', isChecked ? 'true' : 'false');

    $('#offcanvas-right-cart .product-list').find('.select-wrap i').prop('class', isChecked ? checked : unchecked);

    if (!isChecked) {
      $('#offcanvas-right-cart .to-checkout').addClass('disabled')
    } else {
      $('#offcanvas-right-cart .to-checkout').removeClass('disabled')
    }

    const cartSelectionUrl = isChecked
      ? (window.longosStorefrontRoutes?.cartsSelect || 'carts/select')
      : (window.longosStorefrontRoutes?.cartsUnselect || 'carts/unselect');
    $http.post(cartSelectionUrl, {
      cart_ids: checkedIds
    }, {
      hload: true
    }).then((res) => {
      updateMiniCartData(res);
    })
  })

  // 右侧购物车弹出层内交互
  $(document).on("change", "#offcanvas-right-cart .price input", function () {
    const [id, sku_id, quantity] = [$(this).data('id'), $(this).data('sku'), $(this).val() * 1];
    if ($(this).val() === '') $(this).val(1);
    let that = this;
    const cartUpdateUrl = (window.longosStorefrontRoutes?.cartsIndex || 'carts').replace(/\/$/, '') + `/${id}`;
    $http.put(cartUpdateUrl, {
      quantity: quantity,
      sku_id
    }, {
      hload: true
    }).then((res) => {
      if (res.status != 'success') {
        layer.msg(res.message)
        $(that).val(res.data.quantity);
      } else {
        updateMiniCartData(res);
      }
    })
  })

  function updateMiniCartData(res) {
    $('.offcanvas-right-cart-count').text(res.data.quantity);
    $('.offcanvas-right-cart-amount').text(res.data.amount_format);
  }

  // Keep variable-width catalog menus inside the viewport without changing
  // their positioning context. The previous fallback made the parent static
  // and wrote conflicting left/transform values, which collapsed some menus.
  const positionDesktopMenus = () => {
    if (window.matchMedia('(max-width: 991.98px)').matches) return;

    document.querySelectorAll('.menu-wrap > .navbar-nav > .nav-item.dropdown').forEach((item) => {
      const menu = item.querySelector(':scope > .dropdown-menu');
      if (!menu || menu.classList.contains('w-100')) return;

      menu.style.setProperty('--menu-shift-x', '0px');
      const rect = menu.getBoundingClientRect();
      const viewportGutter = 16;
      let shift = 0;

      if (rect.left < viewportGutter) {
        shift = viewportGutter - rect.left;
      } else if (rect.right > window.innerWidth - viewportGutter) {
        shift = (window.innerWidth - viewportGutter) - rect.right;
      }

      menu.style.setProperty('--menu-shift-x', `${Math.round(shift)}px`);
    });
  };

  positionDesktopMenus();
  let desktopMenuResizeTimer;
  window.addEventListener('resize', () => {
    window.clearTimeout(desktopMenuResizeTimer);
    desktopMenuResizeTimer = window.setTimeout(positionDesktopMenus, 100);
  }, { passive: true });

  // Desktop menus are CSS-hover driven, so keep their ARIA state in sync for
  // keyboard and assistive-technology users without changing parent links.
  $('.header-content .nav-item.dropdown').each(function () {
    const item = $(this);
    const trigger = item.children('.nav-link[aria-haspopup="true"]');
    if (!trigger.length) return;
    const close = () => trigger.attr('aria-expanded', 'false');
    const open = () => {
      item.removeClass('is-menu-dismissed');
      trigger.attr('aria-expanded', 'true');
    };
    item.on('mouseenter focusin', open);
    item.on('mouseleave', function () {
      item.removeClass('is-menu-dismissed');
      close();
    });
    item.on('focusout', function () {
      window.setTimeout(() => {
        // Avoid relying on the :focus-within selector in jQuery. Some older
        // storefront browsers parse it inconsistently, while DOM containment
        // expresses the exact condition needed here.
        if (!this.contains(document.activeElement)) {
          item.removeClass('is-menu-dismissed');
          close();
        }
      }, 0);
    });
    trigger.on('keydown', function (event) {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        open();
        item.find('.dropdown-menu a').first().trigger('focus');
      }
      if (event.key === 'Escape') {
        event.preventDefault();
        close();
        item.addClass('is-menu-dismissed');
      }
    });
    item.children('.dropdown-menu').on('keydown', function (event) {
      if (event.key !== 'Escape') return;

      event.preventDefault();
      close();
      trigger.trigger('focus');
      item.addClass('is-menu-dismissed');
    });
  });

  // 手机端个人中心弹出菜单
  (function () {
    $('.mb-account-icon').click(function (e) {
      if ($('.account-sides-wrap').length) {
        e.preventDefault();
      }
      $('.account-sides-wrap').addClass('active');
    })

    $('.mb-header .btn-close').click(function (e) {
      $('.account-sides-wrap').removeClass('active');
    })

    $(document).click(function (e) {
      if (!$(e.target).closest('.account-sides-info').length && !$(e.target).closest('.mb-account-icon').length) {
        $('.account-sides-wrap').removeClass('active');
      }
    })
  }());

  // PC/MB 头部滑动固定
  (function () {
    const pbHeader = $(window).width() > 768 ? $('.header-content') : $('.header-mobile');
    if (!pbHeader.length) return;
    const headerContentTop = pbHeader.offset().top;
    const headerContentHeight = pbHeader.outerHeight(true);
    $(window).scroll(function () {
      const scrollTop = $(this).scrollTop();
      if (scrollTop > headerContentTop) {
        pbHeader.addClass('fixed');
        if (!$('.header-content-placeholder').length)
          pbHeader.before('<div class="header-content-placeholder" style="height: ' + headerContentHeight + 'px"></div>');
      } else {
        pbHeader.removeClass('fixed');
        $('.header-content-placeholder').remove();
      }
    })
  }());
});
