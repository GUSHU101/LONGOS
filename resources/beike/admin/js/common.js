/*
 * @copyright     2022 beikeshop.com - All Rights Reserved.
 * @link          https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @Date          2022-08-22 18:32:26
 * @LastEditTime  2024-12-04 10:16:43
 */

export const adminUrl = (path = '') => {
  const element = document.querySelector('base');
  const base = new URL(element?.href || window.location.href, window.location.origin);
  base.hash = '';
  base.search = '';
  if (!base.pathname.endsWith('/')) base.pathname += '/';

  return new URL(String(path).replace(/^\/+/, ''), base);
};

export const appTimezone = () => document.querySelector('meta[name="app-timezone"]')?.content || 'UTC';

const dateValue = value => {
  if (value instanceof Date) return value;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
};

export const formatInAppTimezone = (value, options = {}) => {
  const date = dateValue(value);
  if (!date) return '';
  const locale = String(document.documentElement.lang || 'en').replace('_', '-');
  try {
    return new Intl.DateTimeFormat(locale, {timeZone: appTimezone(), ...options}).format(date);
  } catch (error) {
    return new Intl.DateTimeFormat(locale, options).format(date);
  }
};

export default {
  timezone() {
    return appTimezone();
  },

  formatDate(value, options = {}) {
    return formatInAppTimezone(value, {year: 'numeric', month: 'short', day: 'numeric', ...options});
  },

  formatDateTime(value, options = {}) {
    return formatInAppTimezone(value, {
      year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
      ...options,
    });
  },

  // 打开文件管理器
  fileManagerIframe(callback, params) {
    const managerUrl = adminUrl('file_manager');
    Object.entries(params || {}).forEach(([key, value]) => managerUrl.searchParams.set(key, String(value)));
    const focusedElement = document.activeElement;
    const width = `${Math.max(280, Math.min(1060, window.innerWidth - 24))}px`;
    const height = `${Math.max(420, Math.min(680, window.innerHeight - 24))}px`;
    const receive = images => {
      const files = window.LongosFileManager?.normalizeMediaFiles(images) || [];
      if (!files.length || typeof callback !== 'function') return;
      callback(files);
    };

    layer.open({
      type: 2,
      title: lang.file_manager,
      shadeClose: false,
      skin: 'file-manager-box',
      scrollbar: false,
      shade: 0.4,
      resize: false,
      area: [width, height],
      content: managerUrl.toString(),
      success: function(layerInstance) {
        var iframe = layerInstance.find("iframe")[0];
        if (!iframe) return;

        const attachCallback = function() {
          try {
            if (iframe.contentWindow) iframe.contentWindow.callback = receive;
          } catch (e) {}
        };
        iframe.addEventListener('load', attachCallback);
        if (iframe.contentDocument?.readyState === 'complete') attachCallback();
      },
      end: function() {
        if (focusedElement && document.contains(focusedElement) && typeof focusedElement.focus === 'function') focusedElement.focus();
      },
    });
  },

  // 防抖
  debounce(fn, delay) {
    var timeout = null; // 创建一个标记用来存放定时器的返回值

    return function (e) {
      // 每当用户输入的时候把前一个 setTimeout clear 掉
      clearTimeout(timeout);
      // 然后又创建一个新的 setTimeout, 这样就能保证interval 间隔内如果时间持续触发，就不会执行 fn 函数
      timeout = setTimeout(() => {
          fn.apply(this, arguments);
      }, delay);
    }
  },

  // 生成随机字符串
  randomString(length = 32) {
    let str = '';
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    for (let i = 0; i < length; i++) {
      str += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return str;
  },

  // 获取url参数
  getQueryString(name, defaultValue) {
    const reg = new RegExp('(^|&)' + name + '=([^&]*)(&|$)');
    const r = window.location.search.substr(1).match(reg);
    if (r != null) {
      return decodeURIComponent(r[2]);
    }

    return typeof(defaultValue) != 'undefined' ? defaultValue : '';
  },

  // 控制字符串长度 超出显示...
  stringLengthInte(text, length = 50) {
    if (text.length) {
      return text.slice(0, length) + (text.length > length ? '...' : '');
    }

    return '';
  },

  // 给列表页筛选开发插件使用，场景：开发者需要添加筛选条件，不需要到filter添加筛选key
  addFilterCondition(app) {
    if (location.search) {
      const params = location.search.substr(1).split('&');
      params.forEach(param => {
        const [key, value] = param.split('=');
        app.$set(app.filter, key, decodeURIComponent(value));
      });
    }
  },

  // 将对象内不为空的转换为url参数 并 添加到url后面
  objectToUrlParams(obj, url) {
    const params = [];
    for (const key in obj) {
      if (obj[key] !== '') {
        params.push(`${key}=${obj[key]}`);
      }
    }

    return `${url}${params.length ? '?' : ''}${params.join('&')}`;
  },

  // 清空对象内的值
  clearObjectValue(obj) {
    for (const key in obj) {
      obj[key] = '';
    }

    return obj;
  },

  // 设置版本更新提示
  versionUpdateTips() {
    // The marketplace and application source are platform-wide resources.
    // Tenant administrators must not call the control-plane-only version API
    // from every admin page, otherwise the expected 403 becomes a noisy toast.
    if (!config.version_check_url) {
      return;
    }

    let data = null;
    try {
      data = JSON.parse(Cookies.get('beike_version') || null);
    } catch (error) {
      Cookies.remove('beike_version');
    }

    if (data) {
      if (data.latest === config.beike_version) {
        return;
      }

      if (data.has_new_version) {
        $('.new-version').text(data.latest);
        $('.update-date').text(data.release_date);
        $('.update-btn').show();
      } else {
        $('.update-btn').hide();
      }
    } else {
      $http.get(config.version_check_url, {version: config.beike_version}, {hload: true}).then((res) => {
        const payload = res && res.data && res.data.data ? res.data.data : (res && res.data ? res.data : res);
        const versionInfo = payload && payload.data ? payload.data : payload;
        if (!versionInfo) {
          return;
        }

        Cookies.set('beike_version', versionInfo, { expires: 7 });

        bk.versionUpdateTips();
      })
    }
  },

  // 列表页使用 vue ajax 分页，点击浏览起前进后退按钮时，重新加载数据
  ajaxPageReloadData(app) {
    window.addEventListener('popstate', () => {
      const page = this.getQueryString('page');

      if (app.page < 2) {
        window.history.back(-1);
        return;
      }

      app.page = page * 1 - 1;
      app.loadData();
    });
  },

  updateQueryStringParameter(uri, key, value) {
    var re = new RegExp("([?&])" + key + "=.*?(&|$)", "i");
    var separator = uri.indexOf('?') !== -1 ? "&" : "?";
    if (uri.match(re)) {
      return uri.replace(re, '$1' + key + "=" + value + '$2');
    } else {
      return uri + separator + key + "=" + value;
    }
  },

  back() {
    window.history.back(-1);
  },

  // table 响应式，可以添加 table-no-mb 类名来禁用
  tableResponsive() {
    if ($(window).width() <= 768) {
      $('.table').each((index, table) => {
        if (!$(table).hasClass('table-no-mb')) {
          $(table).addClass('table-mb')
          $(table).find('tbody tr').each((hi, tr) => {
            $(tr).find('td').each((ti, td) => {
              let th = $(td).parents('table').find('thead th').eq(ti);
              if (th.length) {
                if (th.html().indexOf('checkbox') === -1) {
                  $(td).attr('data-label', th.text())
                } else {
                  $(td).attr('data-label', lang.choose)
                }
              } else {
                $(td).parents('table').removeClass('table-mb')
              }
            })
          })
        }
      })
    }
  },
}
