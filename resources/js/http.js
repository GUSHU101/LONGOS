/*
 * @copyright     2022 beikeshop.com - All Rights Reserved.
 * @link          https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @Date          2022-08-02 19:19:52
 * @LastEditTime  2024-12-05 00:07:57
 */

window.axios = require('axios');

const axiosApi = axios.create({
  baseURL: document.querySelector('base').href, // 自动设置 base
  timeout: 0,
});

export default {
  /**
   * get 请求
   * @param url 接口路由
   * @returns {AxiosPromise<any>}
   */
  axiosApi,

  get (url, params, {hmsg, hload, base, timeout}={}) {
    return this.request('get', url, params = params, {hmsg, hload, base, timeout});
  },

  /**
   * post 请求
   *
   * @param url 接口路由
   * @param params 接口参数
   * @returns {AxiosPromise<any>}
   */

  post (url, params, {hmsg, hload, base, timeout}={}) {
    return this.request('post', url, params, {hmsg, hload, base, timeout});
  },

  /**
  * delete 方法封装
  * @param url
  * @param params
  * @returns {Promise}
  */

  delete (url, params, {hmsg, hload, base, timeout}={}) {
    return this.request('delete', url, params, {hmsg, hload, base, timeout});
  },

  /**
  * put 方法封装
  * @param url
  * @param params
  * @returns {Promise}
  */

  put (url, params, {hmsg, hload, base, timeout}={}) {
    return this.request('put', url, params, {hmsg, hload, base, timeout});
  },


  /**
   * 网络请求
   * @param method 方法
   * @param url 接口地址
   * @param params 参数
   * @param showError 是否展示错误信息
   * @returns {Promise<any>}
   */
  request(method, url, params = {}, {hmsg, hload, base, timeout} = {}) {
    if (!hload) {
      layer.load(2, {shade: [0.3,'#fff'] })
    }

    if (base) {
      axiosApi.defaults.baseURL = base;
    }

    const requestConfig = {method: method, url: url, [method == 'get' ? 'params' : 'data']: params};
    const requestedTimeout = Number(timeout);
    if (Number.isFinite(requestedTimeout) && requestedTimeout > 0) {
      // Keep storefront mutations bounded without imposing a global timeout
      // on intentionally long-running admin uploads and exports.
      requestConfig.timeout = Math.min(Math.floor(requestedTimeout), 120000);
    }

    return new Promise((resolve, reject) => {
      axiosApi(requestConfig).then((res) => {
        if (res) {
          resolve(res.data);
        } else { // 其他情况返回错误信息，根据需要处理
          reject(res.data);
          if (!hmsg) return layer.msg(res.data.message, ()=>{});
        }
      }).catch((res) => {
        reject(res);
        if (!hmsg) {
          if (window.bkHandleAuthExpired && window.bkHandleAuthExpired(res.response)) {
            return;
          }

          layer.msg(res.response?.data?.message || res.message, {time: 3000}, ()=>{});
        }
      }).finally(() => {
        layer.closeAll('loading')
      });
    });
  }
}
