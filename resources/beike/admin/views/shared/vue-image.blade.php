<template id="vue-image">
  <button type="button" class="set-product-img wh-70 p-0" @click="updateImages" aria-label="{{ __('admin/builder.modules_select_image') }}">
    <img v-if="value" :src="thumbnail(value)" class="img-fluid" alt="">
    <i v-else class="bi bi-plus fs-1 text-muted" aria-hidden="true"></i>
  </button>
</template>

<script type="text/javascript">

Vue.component('vue-image', {
  template: '#vue-image',

  props: ['value'],

  data: function () {
    return {
      //
    }
  },

  methods: {
    updateImages() {
      const self = this;
      bk.fileManagerIframe(images => {
        const image = window.LongosFileManager.normalizeMediaFiles(images, 'image')[0];
        if (image) self.$emit('input', image.path);
      }, {mime: 'image', is_multiple: 0})
    },
  }
});
</script>
