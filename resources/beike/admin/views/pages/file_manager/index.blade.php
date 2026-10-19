<!DOCTYPE html>
<html lang="{{ locale() }}">

<head>
  <meta charset="UTF-8">
  <base href="{{ rtrim($admin_base_url, '/') }}/">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="asset" content="{{ asset('/') }}">
  <script src="{{ asset('vendor/vue/2.7/vue.js') }}"></script>
  <script src="{{ asset('vendor/element-ui/index.js') }}"></script>
  <script src="{{ asset('vendor/cookie/js.cookie.min.js') }}"></script>
  <script src="{{ asset('vendor/jquery/jquery-3.6.0.min.js') }}"></script>
  <script src="{{ asset('vendor/layer/3.5.1/layer.js') }}"></script>
  <script src="{{ asset('vendor/vue/batch_select.js') }}"></script>
  <link href="{{ mix('/build/beike/admin/css/bootstrap.css') }}" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('vendor/element-ui/index-blue.css') }}">
  <link href="{{ mix('build/beike/admin/css/filemanager.css') }}" rel="stylesheet">
  <script src="{{ mix('build/beike/admin/js/app.js') }}"></script>
  <title>{{ platform_brand_name() }} File Manager</title>
  <script>
    // The manager can be opened from the native design editor as well as the
    // legacy Layer dialog. Normalize browser locale values such as zh-CN.
    let parentLanguage = document.documentElement.getAttribute('lang') || 'zh_cn';
    try {
      parentLanguage = parent.document.documentElement.getAttribute('lang') || parentLanguage;
    } catch (error) {}
    const htmlLang = String(parentLanguage).replace('-', '_').toLowerCase();

    if (htmlLang != 'zh_cn') {
      const js = document.createElement('script');
      js.src = `vendor/element-ui/language/${htmlLang}.js`;
      document.head.appendChild(js);
      js.onload = () => {
        ELEMENT.locale(ELEMENT.lang[htmlLang])
      }
    }

    const lang = {
      file_manager: '{{ __('admin/file_manager.file_manager') }}',
    }

    const config = {
      beike_version: '{{ config('beike.version') }}',
      api_url: '{{ beike_api_url() }}',
      app_url: '{{ config('app.url') }}',
    }
  </script>
</head>

<body class="page-filemanager">
  <div id="filemanager-wrap-app" v-cloak>
    <a class="filemanager-skip-link" href="#file-manager-content">{{ __('admin/file_manager.skip_to_files') }}</a>
    <div class="filemanager-wrap" ref="splitPane">
      <div class="select-tip"></div>
      <button v-if="mobileFoldersOpen" type="button" class="filemanager-mobile-backdrop" @click="mobileFoldersOpen = false" aria-label="{{ __('common.close') }}"></button>
      <aside id="filemanager-folders" :class="['filemanager-navbar', mobileFoldersOpen ? 'is-mobile-open' : '']" :style="'width:' + paneLengthValue" aria-label="{{ __('admin/file_manager.folders') }}">
        <div class="filemanager-sidebar-head">
          <div class="filemanager-sidebar-brand" aria-hidden="true"><i class="el-icon-picture-outline"></i></div>
          <div class="filemanager-sidebar-copy">
            <strong>{{ __('admin/file_manager.media_library') }}</strong>
            <span>{{ __('admin/file_manager.media_library_subtitle') }}</span>
          </div>
          <button type="button" class="filemanager-sidebar-close" @click="mobileFoldersOpen = false" aria-label="{{ __('common.close') }}">
            <i class="el-icon-close" aria-hidden="true"></i>
          </button>
        </div>
        <div class="filemanager-sidebar-section">
          <span>{{ __('admin/file_manager.folders') }}</span>
          @can('file_manager_create')
          <button type="button" class="sidebar-create-button" @click="openInputBox('addFolder', null, { path: folderCurrent })" aria-label="{{ __('admin/file_manager.create_folder') }}">
            <i class="el-icon-plus" aria-hidden="true"></i>
          </button>
          @endcan
        </div>
        <div v-if="directoryError" class="filemanager-sidebar-error" role="alert">
          <span>@{{ directoryError }}</span>
          <button type="button" @click="loadDirectories">{{ __('admin/file_manager.retry') }}</button>
        </div>
        <el-tree
          :props="defaultProps"
          node-key="path"
          :data="treeData"
          :current-node-key="folderCurrent"
          :default-expanded-keys="defaultkeyarr"
          :expand-on-click-node="false"
          highlight-current ref="tree"
          @can('file_manager_update') draggable @endcan
          :allow-drop="allowDrop"
          @node-drag-start="handleDragStart"
          @node-drop="handleDrop"
          @node-click="handleNodeClick"
          @node-expand="(node) => {updateDefaultExpandedKeys(node, 'expand')}"
          @node-collapse="(node) => {updateDefaultExpandedKeys(node, 'collapse')}" class="tree-wrap">
          <div class="custom-tree-node" slot-scope="{ node, data }" :path="data.path" :name="data.name">
            <div class="folder-name" :path="data.path" :name="data.name">@{{ node.label }}</div>
            <div class="right">
              <el-dropdown trigger="click" placement="bottom-end" @click.native.stop @command="handleFolderCommand($event, node, data)">
                <button type="button" class="tree-action" aria-label="{{ __('admin/file_manager.folder_actions') }}" aria-haspopup="menu">
                  <i class="el-icon-more" aria-hidden="true"></i>
                </button>
                <el-dropdown-menu slot="dropdown">
                  <el-dropdown-item command="download" icon="el-icon-download">{{ __('admin/file_manager.download') }}</el-dropdown-item>
                  @can('file_manager_create')
                  <el-dropdown-item command="addFolder" icon="el-icon-circle-plus-outline">{{ __('admin/file_manager.create_folder') }}</el-dropdown-item>
                  @endcan
                  @can('file_manager_update')
                  <el-dropdown-item v-if="node.level != 1" command="renameFolder" icon="el-icon-edit">{{ __('admin/file_manager.rename') }}</el-dropdown-item>
                  @endcan
                  @can('file_manager_delete')
                  <el-dropdown-item v-if="node.level != 1" command="deleteFolder" icon="el-icon-delete" divided>{{ __('common.delete') }}</el-dropdown-item>
                  @endcan
                </el-dropdown-menu>
              </el-dropdown>
            </div>
          </div>
        </el-tree>
        <div class="filemanager-sidebar-footer">
          <i class="el-icon-collection" aria-hidden="true"></i>
          <span>@{{ fileCountText }}</span>
        </div>
      </aside>
      <div class="filemanager-divider" role="separator" tabindex="0" aria-orientation="vertical"
        :aria-valuemin="min" :aria-valuemax="max" :aria-valuenow="Math.round(paneLengthPercent)"
        @mousedown="handleMouseDown" @keydown.left.prevent="resizePane(-2)" @keydown.right.prevent="resizePane(2)"></div>
      <main id="file-manager-content" class="filemanager-content" v-loading="loading" element-loading-background="rgba(248, 250, 252, 0.72)" :aria-busy="String(loading)" tabindex="-1">
        <div class="content-head">
          <div class="filemanager-heading">
            <button type="button" class="filemanager-folder-toggle" @click="mobileFoldersOpen = !mobileFoldersOpen" :aria-expanded="String(mobileFoldersOpen)" aria-controls="filemanager-folders" aria-label="{{ __('admin/file_manager.folders') }}">
              <i class="el-icon-folder-opened" aria-hidden="true"></i>
            </button>
            <div>
              <h1>@{{ currentFolderName }}</h1>
              <p>@{{ fileCountText }}</p>
            </div>
          </div>
          <div class="filemanager-head-actions">
            @hook('admin.file_manager.content_head.right')
            <el-input class="filemanager-search" v-model="filterKeyword" clearable prefix-icon="el-icon-search"
              placeholder="{{ __('admin/file_manager.search_files') }}" aria-label="{{ __('admin/file_manager.search_files') }}"
              @keyup.enter.native="searchFile" @clear="searchFile"></el-input>
            <div class="filemanager-view-switch" role="group" aria-label="{{ __('admin/file_manager.view_mode') }}">
              <button type="button" :class="{ active: viewMode === 'grid' }" :aria-pressed="String(viewMode === 'grid')" @click="setViewMode('grid')" aria-label="{{ __('admin/file_manager.grid_view') }}"><i class="el-icon-menu" aria-hidden="true"></i></button>
              <button type="button" :class="{ active: viewMode === 'list' }" :aria-pressed="String(viewMode === 'list')" @click="setViewMode('list')" aria-label="{{ __('admin/file_manager.list_view') }}"><i class="el-icon-s-unfold" aria-hidden="true"></i></button>
            </div>
            <el-popover placement="bottom" width="260" class="me-1" trigger="click">
              <div class="text-center mb-3 fw-bold">{{ __('admin/file_manager.file_sorting') }}</div>
              <div class="mb-3">
                <div class="mb-2">{{ __('admin/file_manager.text_type') }}</div>
                <el-radio-group v-model="filter.sort" @input="filterChange" size="small">
                  <el-radio-button label="created">{{ __('admin/file_manager.text_created') }}</el-radio-button>
                  <el-radio-button label="name">{{ __('admin/file_manager.file_name') }}</el-radio-button>
                </el-radio-group>
              </div>

              <div class="mb-3">
                <div class="mb-2">{{ __('admin/file_manager.to_sort') }}</div>
                <el-radio-group v-model="filter.order" @input="filterChange" size="small">
                  <el-radio-button label="desc">{{ __('admin/file_manager.text_desc') }}</el-radio-button>
                  <el-radio-button label="asc">{{ __('admin/file_manager.text_asc') }}</el-radio-button>
                </el-radio-group>
              </div>
              <el-button slot="reference" class="filemanager-icon-button" plain icon="el-icon-s-operation" aria-label="{{ __('admin/file_manager.file_sorting') }}"></el-button>
            </el-popover>
            @can('file_manager_create')
            <el-upload
              ref="mediaUpload"
              class="filemanager-upload"
              action=""
              :accept="uploadAccept"
              :show-file-list="false"
              icon="el-icon-upload2"
              :on-change="handleUploadChange"
              :http-request="uploadFile"
              :multiple="Boolean(isMultiple)">
              <el-button type="primary" icon="el-icon-upload2"><span v-if="uploadsInFlight > 0 || uploadQueue.length">@{{ uploadStatusText }}</span><span v-else>{{ __('admin/file_manager.upload_files') }}</span></el-button>
            </el-upload>
            @endcan
            <el-button v-if="uploadFileDialog.images.length" class="filemanager-icon-button" plain type="info" icon="el-icon-document" aria-label="{{ __('admin/file_manager.upload_queue') }}" @click="openUploadFile">@{{ uploadFileDialog.images.length }}</el-button>
            <span class="upload-status" role="status" aria-live="polite">@{{ (uploadsInFlight > 0 || uploadQueue.length) ? uploadStatusText : '' }}</span>
            @hook('admin.file_manager.content_head.right.after')
          </div>
        </div>
        <div class="filemanager-actionbar">
          <nav class="filemanager-breadcrumbs" aria-label="{{ __('admin/file_manager.current_folder') }}">
            <template v-for="(crumb, index) in folderBreadcrumbs">
              <button type="button" :key="crumb.path" :aria-current="index === folderBreadcrumbs.length - 1 ? 'page' : null" @click="navigateToFolder(crumb.path)">@{{ crumb.name }}</button>
              <i v-if="index < folderBreadcrumbs.length - 1" :key="crumb.path + '-separator'" class="el-icon-arrow-right" aria-hidden="true"></i>
            </template>
          </nav>
          <div class="filemanager-selection-actions">
            @hook('admin.file_manager.content.head.btns.before')
            <el-button type="primary" icon="el-icon-check" @click="fileChecked" :disabled="!selectImageIndex.length">{{ __('admin/builder.modules_choose') }}</el-button>
            <el-button icon="el-icon-view" @click="viewImages" :disabled="!selectImageIndex.length">{{ __('common.view') }}</el-button>
            <el-button icon="el-icon-document-copy" @click="copyLink" :disabled="!selectImageIndex.length">{{ __('admin/file_manager.copy_link') }}</el-button>
            @can('file_manager_update')
            <el-button icon="el-icon-edit" @click="openInputBox('image')" :disabled="selectImageIndex.length !== 1">{{ __('admin/file_manager.rename') }}</el-button>
            @endcan
            @can('file_manager_delete')
            <el-button icon="el-icon-delete" @click="deleteFile" :disabled="!selectImageIndex.length">{{ __('common.delete') }}</el-button>
            @endcan
            <el-button v-if="isMultiple" icon="el-icon-finished" @click="selectAll" :disabled="!images.length">{{ __('common.select_all') }}</el-button>
            @hook('admin.file_manager.content.head.btns.after')
          </div>
        </div>
        <div :class="['content-center', 'view-' + viewMode, { 'drag-over': isDragOver }]"
          role="listbox"
          :aria-multiselectable="String(Boolean(isMultiple))"
          :aria-busy="String(loading)"
          aria-label="{{ __('admin/file_manager.file_manager') }}"
          @dragover.prevent="canCreateMedia && (isDragOver = true)"
          @dragleave="handleDragLeave"
          @drop.prevent="onDrop"
          v-batch-select="{ className: '.image-list', selectImageIndex, setSelectStatus: updateSelectStatus, imgMove: imgMove }">
          <div class="drag-drop-upload-text" role="status">
            <span><i class="el-icon-upload2" aria-hidden="true"></i></span>
            <strong>{{ __('admin/file_manager.drop_upload_title') }}</strong>
            <small>{{ __('admin/file_manager.drop_upload_hint') }}</small>
          </div>
          <div v-if="loadError" class="filemanager-state filemanager-error-state" role="alert">
            <span class="filemanager-state-icon"><i class="el-icon-warning-outline" aria-hidden="true"></i></span>
            <h2>{{ __('admin/file_manager.load_failed') }}</h2>
            <p>@{{ loadError }}</p>
            <el-button type="primary" icon="el-icon-refresh" @click="loadData">{{ __('admin/file_manager.retry') }}</el-button>
          </div>
          <template v-else-if="images.length">
          <div :class="['image-list', file.selected ? 'active' : '']"
            v-for="file, index in images" :key="file.path || index"
            role="option" tabindex="0" :aria-selected="String(Boolean(file.selected))" :aria-label="mediaAriaLabel(file)"
            @click="checkedImage(index)" @dblclick="checkedImageDouble(index)"
            @keydown="handleMediaKeydown($event, index)">
            <span class="media-selection-mark" aria-hidden="true"><i class="el-icon-check"></i></span>
            <div :class="['img', file.mime == 'video/mp4' ? 'product-item-video' : '']">
              <button type="button" @click.stop="showVideo(file.origin_url || file.url)" v-if="file.mime == 'video/mp4'" class="video-icon" aria-label="{{ __('common.view') }}"><i class="bi bi-play-circle" aria-hidden="true"></i></button>
              <video :src="file.url" class="img-fluid" v-if="file.mime == 'video/mp4'" preload="metadata"></video>
              <img v-else :src="file.url" :alt="file.name" draggable="false" loading="lazy" />
            </div>
            <div class="text media-card-copy">
              <span class="media-card-name" :title="file.name">@{{ file.name }}</span>
              <span class="media-card-meta">@{{ fileTypeLabel(file) }}<template v-if="Number(file.size) > 0"> · @{{ formatFileSize(file.size) }}</template></span>
              <time v-if="file.modified_at" class="media-card-date" :datetime="formatIsoDate(file.modified_at)">@{{ formatFileDate(file.modified_at) }}</time>
            </div>
          </div>
          </template>
          <div v-else class="filemanager-state filemanager-empty-state">
            <span class="filemanager-state-icon"><i class="el-icon-picture-outline" aria-hidden="true"></i></span>
            <h2>{{ __('admin/file_manager.empty_title') }}</h2>
            @can('file_manager_create')
            <p>{{ __('admin/file_manager.empty_upload_hint') }}</p>
            <el-button type="primary" icon="el-icon-upload2" @click="openUploadPicker">{{ __('admin/file_manager.upload_files') }}</el-button>
            @else
            <p>{{ __('admin/file_manager.no_file') }}</p>
            @endcan
          </div>
        </div>
        <div class="content-footer">
          <div class="right selection-status" role="status" aria-live="polite">@{{ selectionStatusText }}</div>
          <div class="pagination-wrap">
            <el-pagination @size-change="pageSizeChange" @current-change="pageCurrentChange" :current-page="image_page"
              :page-sizes="[20, 40, 60, 80, 100]" :page-size="20" layout="total, sizes, prev, pager, next, jumper"
              :total="image_total">
            </el-pagination>

          </div>
          <div class="right">

          </div>
        </div>
      </main>

      <el-dialog title="{{ __('common.text_hint') }}" top="4vh" :visible.sync="uploadFileDialog.show"
        width="580px" @close="uploadFileDialogClose" custom-class="upload-wrap">
        <div class="alert alert-info mb-3">
          {{ __('admin/file_manager.upload_hint_2', ['max_size' => $uploadMaxLabel]) }}
          <span class="fw-bold">{{ __('admin/file_manager.upload_limit_help') }}</span>
        </div>
        <div class="upload-image">
          <div v-for="image, index in uploadFileDialog.images" :key="image.uid || index" class="list">
            <div class="info">
              <div class="name">@{{ index + 1 }}. @{{ image.name }}</div>
              <div class="status">
                <span v-if="image.status == 'complete'" class="text-success">{{ __('admin/file_manager.finish') }}</span>
                <span v-else-if="image.status == 'fail'" class="text-danger">{{ __('admin/file_manager.upload_fail')
                  }}</span>
                <span v-else-if="image.status == 'canceled'" class="text-muted">{{ __('admin/file_manager.upload_canceled') }}</span>
                <span v-else-if="image.status == 'queued'" class="text-muted">{{ __('admin/file_manager.upload_queued') }}</span>
                <span v-else>{{ __('admin/file_manager.uploading') }}</span>
              </div>
            </div>
            <el-progress :percentage="image.progre" :status="image.status == 'fail' ? 'exception' : (image.status == 'complete' ? 'success' : undefined)"
              :show-text="false" :stroke-width="4"></el-progress>
            <div v-if="image.fail_text" class="mt-1 text-danger" v-text="image.fail_text"></div>
            <div class="upload-actions mt-2">
              <el-button v-if="image.status == 'queued' || image.status == 'uploading'" type="text" size="mini" @click="cancelUpload(image)">{{ __('common.cancel') }}</el-button>
              <el-button v-if="image.status == 'fail' || image.status == 'canceled'" type="text" size="mini" @click="retryUpload(image)">{{ __('admin/file_manager.retry_upload') }}</el-button>
              <el-button v-if="image.status == 'fail' || image.status == 'canceled' || image.status == 'complete'" type="text" size="mini" @click="dismissUpload(image)">{{ __('admin/file_manager.dismiss_upload') }}</el-button>
            </div>
          </div>
        </div>
      </el-dialog>

      <div class="drop_file_hint d-none">{!! __('admin/file_manager.drop_file_hint') !!}</div>
      <div class="drop_folder_hint d-none">{!! __('admin/file_manager.drop_folder_hint') !!}</div>
    </div>
  </div>

  @stack('admin.file_manager.footer')

  <script>
    @hook('admin.file_manager.script.before')
    var callback = null;

    var app = new Vue({
      el: '#filemanager-wrap-app',
      components: {},
      data: {
        min: 10,
        max: 40,
        isDragOver: false,
        mobileFoldersOpen: false,
        paneLengthPercent: 26,
        triggerLength: 10,
        isShift: false,
        isCtrl: false,
        mime: @json(request('mime')),
        isMultiple: {{ request('is_multiple', true) }}, // 是否允许多选
        canCreateMedia: @json(auth()->check() && auth()->user()->can('file_manager_create')),
        canUpdateMedia: @json(auth()->check() && auth()->user()->can('file_manager_update')),
        loading: false,
        isBatchSelect: false, // 当前是否正在是否批量选择
        selectImageIndex: [],
        filterKeyword: '',
        filter: {
          sort: 'created',
          order: 'desc',
          keyword: ''
        },

        treeData: [{
          name: '{{ __('admin/file_manager.all_media') }}',
          path: '/',
          children: @json($directories)
        }],

        copyTreeData: [], // 用于恢复树形结构

        defaultProps: {
          children: 'children',
          label: 'name',
          isLeaf: 'leaf'
        },
        selectIdxs: [],

        uploadFileDialog: {
          show: false,
          total: 0,
          images: []
        },
        uploadsInFlight: 0,
        uploadQueue: [],
        recentUploads: [],
        loadRequestId: 0,
        loadError: '',
        directoryError: '',
        viewMode: 'grid',

        folderCurrent: '/',
        defaultkeyarr: ['/'],

        triggerLeftOffset: 0,
        uploadMaxFilesize: @json($maxSizeBytes),

        images: [],
        image_total: 0,
        image_page: 1,
        per_page: 20,
        @stack('admin.file_manager.vue.data')
      },
      // 计算属性
      computed: {
        paneLengthValue() {
          return `calc(${this.paneLengthPercent}% - ${this.triggerLength / 2 + 'px'})`
        },

        uploadAccept() {
          if (this.mime === 'video') return 'video/mp4,.mp4';
          if (this.mime === 'image') return 'image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp';
          return 'image/jpeg,image/png,image/gif,image/webp,video/mp4,.jpg,.jpeg,.png,.gif,.webp,.mp4';
        },

        uploadStatusText() {
          return @json(__('admin/file_manager.uploading_count')).replace(':count', String(this.uploadsInFlight + this.uploadQueue.length));
        },

        selectionStatusText() {
          return this.selectImageIndex.length
            ? @json(__('admin/file_manager.selected_count')).replace(':count', String(this.selectImageIndex.length))
            : @json(__('admin/file_manager.none_selected'));
        },

        fileCountText() {
          return @json(__('admin/file_manager.files_count')).replace(':count', String(this.image_total));
        },

        currentFolderName() {
          if (this.folderCurrent === '/') return @json(__('admin/file_manager.all_media'));
          const parts = this.folderCurrent.split('/').filter(Boolean);
          return parts[parts.length - 1] || @json(__('admin/file_manager.all_media'));
        },

        folderBreadcrumbs() {
          const crumbs = [{ name: @json(__('admin/file_manager.all_media')), path: '/' }];
          const parts = this.folderCurrent.split('/').filter(Boolean);
          let path = '';
          parts.forEach(name => {
            path += `/${name}`;
            crumbs.push({ name, path });
          });
          return crumbs;
        },

        @hook('admin.file_manager.vue.computed')
      },
      // 侦听器
      watch: {
        images: {
          handler(val) {
            if (this.isBatchSelect) return;
            // 将选中的图片索引放入 selectImageIndex，未选中则清空
            this.selectImageIndex = val.filter(item => item.selected).map(e => this.images.indexOf(e));
          },
          deep: true
        },

        selectImageIndex(indexs) {
          this.images.forEach((item, index) => {
            item.selected = indexs.includes(index);
          });
        },

        @hook('admin.file_manager.vue.watch')
      },

      created() {
        const defaultkeyarr = sessionStorage.getItem('defaultkeyarr');
        const folderCurrent = sessionStorage.getItem('folderCurrent');
        let fileManagerFilter = {};
        try {
          fileManagerFilter = JSON.parse(localStorage.getItem('file_manager_filter') || '{}');
        } catch (error) {
          localStorage.removeItem('file_manager_filter');
        }

        if (defaultkeyarr) {
          this.defaultkeyarr = defaultkeyarr.split(',');
        }

        if (folderCurrent) {
          this.folderCurrent = folderCurrent;
        }

        if (fileManagerFilter.sort) {
          this.filter.sort = fileManagerFilter.sort;
        }

        if (fileManagerFilter.order) {
          this.filter.order = fileManagerFilter.order;
        }

        const savedViewMode = localStorage.getItem('file_manager_view_mode');
        if (savedViewMode === 'grid' || savedViewMode === 'list') {
          this.viewMode = savedViewMode;
        }

        @hook('admin.file_manager.created')
      },

      // 实例被挂载后调用
      mounted() {
        this.loadData()

        document.addEventListener('keydown', this.handleDocumentKeydown)
        document.addEventListener('keyup', this.handleDocumentKeyup)
        document.addEventListener('click', this.handleDocumentClick)

        @hook('admin.file_manager.mounted')
      },

      beforeDestroy() {
        document.removeEventListener('keydown', this.handleDocumentKeydown)
        document.removeEventListener('keyup', this.handleDocumentKeyup)
        document.removeEventListener('click', this.handleDocumentClick)
        document.removeEventListener('mousemove', this.handleMouseMove)
        document.removeEventListener('mouseup', this.handleMouseUp)
        this.uploadFileDialog.images.forEach(item => item.controller?.abort())
      },

      methods: {
        handleFolderCommand(command, node, data) {
          if (command === 'deleteFolder') {
            this.deleteFolder(node, data);
            return;
          }
          this.openInputBox(command, node, data);
        },

        setViewMode(mode) {
          if (mode !== 'grid' && mode !== 'list') return;
          this.viewMode = mode;
          localStorage.setItem('file_manager_view_mode', mode);
        },

        navigateToFolder(path) {
          if (!path || path === this.folderCurrent) return;
          this.folderCurrent = path;
          this.filterKeyword = '';
          this.image_page = 1;
          this.mobileFoldersOpen = false;
          sessionStorage.setItem('folderCurrent', path);
          this.$refs.tree?.setCurrentKey(path);
          this.loadData();
        },

        openUploadPicker() {
          const input = this.$refs.mediaUpload?.$el?.querySelector('input[type="file"]');
          if (input) input.click();
        },

        formatFileSize(bytes) {
          const value = Math.max(0, Number(bytes) || 0);
          if (!value) return '0 B';
          const units = ['B', 'KB', 'MB', 'GB'];
          const unitIndex = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
          const amount = value / Math.pow(1024, unitIndex);
          return `${amount >= 10 || unitIndex === 0 ? amount.toFixed(0) : amount.toFixed(1)} ${units[unitIndex]}`;
        },

        formatIsoDate(timestamp) {
          const value = Number(timestamp);
          if (!value) return '';
          const date = new Date(value * 1000);
          return Number.isNaN(date.getTime()) ? '' : date.toISOString();
        },

        formatFileDate(timestamp) {
          const value = Number(timestamp);
          if (!value) return '';
          const date = new Date(value * 1000);
          if (Number.isNaN(date.getTime())) return '';
          const locale = String(document.documentElement.lang || '').replace('_', '-') || undefined;
          try {
            return new Intl.DateTimeFormat(locale, {
              timeZone: window.bk?.timezone?.() || 'UTC', year: 'numeric', month: 'short', day: 'numeric'
            }).format(date);
          } catch (error) {
            return date.toLocaleDateString();
          }
        },

        fileTypeLabel(file) {
          if (String(file?.mime || '').startsWith('video/')) return @json(__('admin/file_manager.video_type'));
          if (String(file?.mime || '').startsWith('image/')) return @json(__('admin/file_manager.image_type'));
          return @json(__('admin/file_manager.unknown_type'));
        },

        mediaAriaLabel(file) {
          const details = [file?.name, this.fileTypeLabel(file)];
          if (Number(file?.size) > 0) details.push(this.formatFileSize(file.size));
          return details.filter(Boolean).join(', ');
        },

        handleDocumentKeydown(e) {
          this.isShift = Boolean(e.shiftKey);
          this.isCtrl = Boolean(e.ctrlKey || e.metaKey);
          if (e.key === 'Escape' && this.mobileFoldersOpen) {
            this.mobileFoldersOpen = false;
          }
        },

        handleDocumentKeyup(e) {
          this.isShift = Boolean(e.shiftKey);
          this.isCtrl = Boolean(e.ctrlKey || e.metaKey);
        },

        handleDocumentClick(e) {
          if (!this.isMultiple || this.isBatchSelect) return;
          const target = e.target instanceof Element ? e.target : null;
          if (target && (target.classList.contains('filemanager-navbar') || target.classList.contains('content-center'))) {
            this.applySelection([]);
          }
        },

        showVideo(src) {
          const safeSrc = window.LongosFileManager.normalizeMediaUrl(src);
          if (!safeSrc) return;
          const player = document.createElement('video');
          player.src = safeSrc;
          player.controls = true;
          player.autoplay = false;
          player.playsInline = true;
          player.className = 'product-video-preview-player';
          const container = document.createElement('div');
          container.className = 'product-video-preview-dialog';
          container.appendChild(player);
          layer.open({
            type: 1,
            title: false,
            closeBtn: 1,
            shadeClose: true,
            area: ['500px', '500px'],
            skin: 'product-video-preview-layer',
            content: container,
          });
        },

        handleDragLeave(e) {
          if (!e.currentTarget.contains(e.relatedTarget)) {
            this.isDragOver = false
          }
        },

        onDrop(e) {
          this.isDragOver = false;
          if (!this.canCreateMedia) return;
          const files = Array.from(e.dataTransfer.files);

          if (files.length > 0) {
            (this.isMultiple ? files : files.slice(0, 1)).forEach(file => {
              this.uploadFile({file: file}).catch(() => {})
            })
          }
        },

        searchFile() {
          this.image_page = 1;
          this.loadData()
        },

        filterChange() {
          this.image_page = 1;
          localStorage.setItem('file_manager_filter', JSON.stringify(this.filter));
          this.loadData()
        },

        loadDirectories() {
          this.directoryError = '';
          $http.get('file_manager/directories').then((res) => {
            this.treeData[0].children = Array.isArray(res) ? res : [];
          }).catch((error) => {
            this.directoryError = error?.response?.data?.message || @json(__('admin/file_manager.directory_load_failed'));
          })
        },

        handleNodeClick(e, node) {
          if (e.path == this.folderCurrent) {
            return;
          }

          // 重置搜索框
          this.filterKeyword = '';
          this.folderCurrent = e.path
          this.mobileFoldersOpen = false
          this.image_page = 1;
          sessionStorage.setItem('folderCurrent', this.folderCurrent);
          this.loadData(e, node)
        },

        updateSelectStatus(status) {
          this.isBatchSelect = status
        },

        pageCurrentChange(e) {
          this.image_page = e
          this.loadData()
        },

        pageSizeChange(e) {
          this.per_page = e
          this.loadData()
        },

        uploadFileDialogClose() {
          this.uploadFileDialog.images = this.uploadFileDialog.images.filter(item => ['queued', 'uploading'].includes(item.status));
        },

        openUploadFile() {
          this.uploadFileDialog.show = true
        },

        handleDrop(draggingNode, dropNode, dropType, ev) {
          const name = draggingNode.data.name;
          const path = draggingNode.data.path;
          const dropPath = dropNode.data.path;
          const dropName = dropNode.data.name;
          let dropNodeChildNodes = dropNode.childNodes;

          $('.drop_folder_hint').find('span:first-child').text(name).siblings('span').text(dropPath);
          this.$confirm($('.drop_folder_hint').html(),"{{ __('common.text_hint') }}", {
            dangerouslyUseHTMLString: true,
            type: "warning"
          }).then(() => {
            $http.post('file_manager/move_directories', {source_path:path, dest_path:dropPath }).then((res) => {
              const newBasePath = dropPath === '/' ? `/${name}` : `${dropPath}/${name}`;

              // 递归更新子节点路径
              const updateChildrenPath = (node, oldBasePath, newBasePath) => {
                if (!node || !node.data) return;

                // 更新当前节点的路径
                if (node.data.path && node.data.path.startsWith(oldBasePath)) {
                  const relativePath = node.data.path.substring(oldBasePath.length);
                  node.data.path = newBasePath + relativePath;
                }

                // 递归更新子节点
                if (node.childNodes && node.childNodes.length > 0) {
                  node.childNodes.forEach(child => {
                    updateChildrenPath(child, oldBasePath, newBasePath);
                  });
                }
              };

              // 更新当前节点的路径
              draggingNode.data.path = newBasePath;

              // 递归更新所有子节点的路径
              updateChildrenPath(draggingNode, path, newBasePath);

              // 如果当前激活目录是被移动的目录或其子目录，则更新当前激活目录
              if (this.folderCurrent === path || this.folderCurrent.startsWith(path + '/')) {
                const relativePath = this.folderCurrent.substring(path.length);
                this.folderCurrent = newBasePath + relativePath;
                sessionStorage.setItem('folderCurrent', this.folderCurrent);
              }

              this.loadData();
            })
          }).catch(() => {
            this.treeData = this.copyTreeData
          });
        },

        handleDragStart(e) {
          this.copyTreeData = JSON.parse(
            JSON.stringify(this.treeData)
          );
        },

        randomString() {
          return bk.randomString()
        },

        // allowDrop
        allowDrop(draggingNode, dropNode, type) {
          if (type == 'prev' || type == 'next') {
            return;
          }

          return true;
        },

        // 图片拖动到文件夹
        imgMove(path, name, selectImageIndex) {
          if (!this.canUpdateMedia) return;
          $('.drop_file_hint').find('span:first-child').text(selectImageIndex.length).siblings('span').text(name);
          this.$confirm($('.drop_file_hint').html(),"{{ __('common.text_hint') }}", {
            dangerouslyUseHTMLString:true,
            type: "warning"
          }).then(() => {
            const imagePaths = this.images.filter((item, index) => selectImageIndex.includes(index)).map(e => e.path);

            $http.post('file_manager/move_files', {images:imagePaths, dest_path:path }).then((res) => {
              this.loadData()
            })
          })
        },

        // 文件上传
        uploadFile(upload) {
          const rawFile = upload && upload.file;
          if (!rawFile) return Promise.reject(new Error('Missing file'));

          const candidate = window.LongosFileManager.normalizeMediaFile({
            path: `/image/catalog/${rawFile.name}`,
            url: `/image/catalog/${rawFile.name}`,
            name: rawFile.name,
            mime: rawFile.type || '',
          });
          if (!candidate || !window.LongosFileManager.mediaFileMatchesKind(candidate, this.mime || '')) {
            const message = this.mime === 'video'
              ? '{{ __('admin/file_manager.verify_select_video') }}'
              : '{{ __('admin/file_manager.verify_select_image') }}';
            layer.msg(message, () => {});
            return Promise.reject(new Error(message));
          }

          const context = {
            folderCurrent: this.folderCurrent,
            imagePage: this.image_page,
            perPage: this.per_page,
            sort: this.filter.sort,
            keyword: this.filterKeyword,
            order: this.filter.order,
          };
          const newFile = {
            uid: rawFile.uid || `${Date.now()}-${rawFile.name}`,
            name: rawFile.name,
            progre: 0,
            status: 'queued',
            fail_text: '',
            rawFile,
            context,
            controller: null,
          };
          this.uploadFileDialog.images.push(newFile);

          return new Promise((resolve, reject) => {
            this.uploadQueue.push({upload, rawFile, newFile, context, resolve, reject});
            this.processUploadQueue();
          });
        },

        cancelUpload(image) {
          const queueIndex = this.uploadQueue.findIndex(task => task.newFile === image);
          if (queueIndex >= 0) {
            const [task] = this.uploadQueue.splice(queueIndex, 1);
            image.status = 'canceled';
            image.fail_text = '{{ __('admin/file_manager.upload_canceled') }}';
            const error = new Error(image.fail_text);
            error.code = 'ERR_CANCELED';
            task.reject(error);
            return;
          }
          if (image.controller && typeof image.controller.abort === 'function') {
            image.controller.abort();
          }
        },

        retryUpload(image) {
          const rawFile = image && image.rawFile;
          if (!rawFile) return;
          this.dismissUpload(image);
          this.uploadFile({file: rawFile}).catch(() => {});
        },

        dismissUpload(image) {
          const index = this.uploadFileDialog.images.indexOf(image);
          if (index >= 0) this.uploadFileDialog.images.splice(index, 1);
          if (!this.uploadFileDialog.images.length) this.uploadFileDialog.show = false;
        },

        processUploadQueue() {
          while (this.uploadsInFlight < 3 && this.uploadQueue.length) {
            const task = this.uploadQueue.shift();
            this.uploadsInFlight += 1;
            this.performUpload(task);
          }
        },

        performUpload(task) {
          const {upload, rawFile, newFile, context, resolve, reject} = task;
          newFile.status = 'uploading';
          newFile.controller = typeof AbortController === 'function' ? new AbortController() : null;
          const formData = new FormData();
          formData.append("file", rawFile, rawFile.name);
          formData.append("path", context.folderCurrent);
          formData.append("base_folder", context.folderCurrent);
          formData.append("page", context.imagePage);
          formData.append("per_page", context.perPage);
          formData.append("sort", context.sort);
          formData.append("keyword", context.keyword);
          formData.append("order", context.order);
          if (this.mime) formData.append("mime", this.mime);

          $http.axiosApi.post('file_manager/upload', formData, {
            ...(newFile.controller ? {signal: newFile.controller.signal} : {}),
            onUploadProgress: (event) => {
              const total = Number(event.total || rawFile.size || 0);
              const percent = total > 0 ? Math.min(99, Math.round((Number(event.loaded || 0) / total) * 100)) : 0;
              newFile.progre = percent;
              if (typeof upload.onProgress === 'function') upload.onProgress({percent});
            }
          }).then((response) => {
            const res = response && response.data ? response.data : response;
            const uploadedFile = window.LongosFileManager.normalizeMediaFile(res && (res.uploaded_file || res));
            if (!uploadedFile) throw new Error('{{ __('admin/file_manager.upload_fail') }}');

            newFile.status = 'complete';
            newFile.progre = 100;
            if (this.folderCurrent === context.folderCurrent) {
              if (this.isMultiple) {
                this.recentUploads = [...this.recentUploads.filter(item => item.path !== uploadedFile.path), uploadedFile];
              } else {
                this.recentUploads = [uploadedFile];
              }
              this.applyFilePayload(res.files, uploadedFile);
            }
            resolve(res);
          }).catch((err) => {
            const canceled = Boolean((newFile.controller && newFile.controller.signal.aborted) || (err && err.code === 'ERR_CANCELED'));
            newFile.status = canceled ? 'canceled' : 'fail';
            newFile.progre = 100;
            this.uploadFileDialog.show = true;
            let message = canceled
              ? '{{ __('admin/file_manager.upload_canceled') }}'
              : (err && err.response && err.response.data && err.response.data.message
              ? err.response.data.message
              : (err && err.message ? err.message : '{{ __('admin/file_manager.upload_fail') }}'));
            if (!canceled && rawFile.size > this.uploadMaxFilesize) {
              message = '{{ __('admin/file_manager.upload_size_fail') }}';
            }
            newFile.fail_text = message;
            reject(err);
          }).finally(() => {
            this.uploadsInFlight = Math.max(0, this.uploadsInFlight - 1);
            this.processUploadQueue();
            if (this.uploadsInFlight === 0 && this.uploadQueue.length === 0) {
              const failures = this.uploadFileDialog.images.filter(item => ['fail', 'canceled'].includes(item.status));
              this.uploadFileDialog.images = failures;
              this.uploadFileDialog.show = failures.length > 0;
            }
          });
        },

        normalizeFiles(files) {
          return (Array.isArray(files) ? files : [])
            .map(file => window.LongosFileManager.normalizeMediaFile(file))
            .filter(Boolean);
        },

        applyFilePayload(payload, uploadedFile = null) {
          const responseFiles = payload && Array.isArray(payload.images) ? payload.images : [];
          const files = this.normalizeFiles(responseFiles);
          const preferred = this.normalizeFiles([
            ...this.recentUploads,
            ...(uploadedFile ? [uploadedFile] : []),
          ]);
          preferred.forEach(file => {
            if (!files.some(item => item.path === file.path)) files.unshift(file);
          });
          const selectedPaths = new Set(preferred.map(file => file.path));
          files.forEach(file => { file.selected = selectedPaths.has(file.path); });
          this.images = files;
          this.image_page = Math.max(1, Number(payload && payload.image_page) || 1);
          this.image_total = Math.max(preferred.length, Number(payload && payload.image_total) || files.length);
          if (selectedPaths.size) {
            this.$nextTick(() => this.$el.querySelector('.image-list.active')?.scrollIntoView({block: 'nearest'}));
          }
        },

        handleUploadChange(e) {
          // console.log(e);
          // console.log('handleUploadChange');
        },

        updateDefaultExpandedKeys(node, type) {
          let defaultkeyarr = sessionStorage.getItem('defaultkeyarr') ? sessionStorage.getItem('defaultkeyarr').split(',') : [];
          const isExist = defaultkeyarr.some(item => item === node.path)

          if (type == 'expand') {
            if (!isExist) {
              defaultkeyarr.push(node.path)
            }
          } else {
            const index = defaultkeyarr.findIndex(e => e == node.path);
            if (index > -1) {
              defaultkeyarr.splice(index, 1);
              // 删除以 node.path 开头的所有元素，除了当前激活目录 -> this.folderCurrent
              defaultkeyarr = defaultkeyarr.filter(e => e == this.folderCurrent || !e.startsWith(node.path));
            }
          }

          sessionStorage.setItem('defaultkeyarr', defaultkeyarr);
        },

        loadData(e, node) {
          const requestId = ++this.loadRequestId;
          const requestedFolder = this.folderCurrent;
          this.loading = true;
          this.loadError = '';

          return $http.get(`file_manager/files?base_folder=${encodeURIComponent(requestedFolder)}`, {
            page: this.image_page,
            per_page: this.per_page,
            sort: this.filter.sort,
            keyword: this.filterKeyword,
            order: this.filter.order,
            mime: this.mime || ''
          }, {
            hload: true
          }).then((res) => {
            if (requestId !== this.loadRequestId || requestedFolder !== this.folderCurrent) return;
            this.recentUploads = [];
            this.applyFilePayload(res);

            if (node) {
              node.expanded = true
              this.updateDefaultExpandedKeys(node.data, 'expand')
            }
          }).catch((error) => {
            if (requestId !== this.loadRequestId || requestedFolder !== this.folderCurrent) return;
            this.loadError = error?.response?.data?.message || error?.message || @json(__('admin/file_manager.load_failed'));
          }).finally(() => {
            if (requestId === this.loadRequestId) this.loading = false;
          });
        },

        // 按下滑动器
        handleMouseDown(e) {
          document.addEventListener('mousemove', this.handleMouseMove)
          document.addEventListener('mouseup', this.handleMouseUp)

          this.triggerLeftOffset = e.pageX - e.currentTarget.getBoundingClientRect().left
        },

        resizePane(delta) {
          this.paneLengthPercent = Math.min(this.max, Math.max(this.min, this.paneLengthPercent + delta));
        },

        // 按下滑动器后移动鼠标
        handleMouseMove(e) {
          const clientRect = this.$refs.splitPane.getBoundingClientRect()
          let paneLengthPercent = 0

          const offset = e.pageX - clientRect.left - this.triggerLeftOffset + this.triggerLength / 2
          paneLengthPercent = (offset / clientRect.width) * 100

          if (paneLengthPercent < this.min) {
            paneLengthPercent = this.min
          }
          if (paneLengthPercent > this.max) {
            paneLengthPercent = this.max
          }
          this.paneLengthPercent = paneLengthPercent;
        },

        // 松开滑动器
        handleMouseUp() {
          document.removeEventListener('mousemove', this.handleMouseMove)
        },

        checkedImage(index) {
          this.recentUploads = [];
          const selected = window.LongosFileManager.nextMediaSelection(this.selectImageIndex, index, this.images.length, {
            multiple: Boolean(this.isMultiple),
            shift: Boolean(this.isMultiple && this.isShift),
            toggle: Boolean(this.isMultiple && this.isCtrl),
          });
          this.applySelection(selected);
        },

        checkedImageDouble(index) {
          this.recentUploads = [];
          this.applySelection([index]);
          this.$nextTick(() => this.fileChecked());
        },

        handleMediaKeydown(event, index) {
          if (event.key === ' ' || event.key === 'Spacebar') {
            event.preventDefault();
            this.checkedImage(index);
            return;
          }
          if (event.key === 'Enter') {
            event.preventDefault();
            this.checkedImageDouble(index);
            return;
          }

          const optionElements = Array.from(this.$el.querySelectorAll('.image-list'));
          if (!optionElements.length) return;
          let targetIndex = index;
          if (event.key === 'Home') targetIndex = 0;
          else if (event.key === 'End') targetIndex = optionElements.length - 1;
          else if (event.key === 'ArrowLeft') targetIndex = Math.max(0, index - 1);
          else if (event.key === 'ArrowRight') targetIndex = Math.min(optionElements.length - 1, index + 1);
          else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            const firstTop = optionElements[0].getBoundingClientRect().top;
            const columns = this.viewMode === 'list'
              ? 1
              : Math.max(1, optionElements.filter(element => Math.abs(element.getBoundingClientRect().top - firstTop) < 4).length);
            targetIndex = event.key === 'ArrowUp'
              ? Math.max(0, index - columns)
              : Math.min(optionElements.length - 1, index + columns);
          } else {
            return;
          }

          event.preventDefault();
          this.recentUploads = [];
          this.applySelection([targetIndex]);
          this.$nextTick(() => optionElements[targetIndex]?.focus());
        },

        applySelection(indexes) {
          const selected = [...new Set(indexes)].filter(index => Number.isInteger(index) && index >= 0 && index < this.images.length);
          this.images.forEach((file, index) => { file.selected = selected.includes(index); });
          this.selectImageIndex = selected;
        },

        // 选取
        fileChecked() {
          let typedFiles = this.normalizeFiles(this.images.filter(e => e.selected));
          if (!typedFiles.length) return;
          if (!this.isMultiple) typedFiles = typedFiles.slice(0, 1);

          if (this.mime) {
            if (this.mime == 'image' && typedFiles.some(e => !window.LongosFileManager.mediaFileMatchesKind(e, 'image'))) {
              layer.msg('{{ __('admin/file_manager.verify_select_image') }}', () => {});
              return;
            }

            if (this.mime == 'video' && typedFiles.some(e => !window.LongosFileManager.mediaFileMatchesKind(e, 'video'))) {
              layer.msg('{{ __('admin/file_manager.verify_select_video') }}', () => {});
              return;
            }
          }

          if (typeof callback === 'function') {
            callback(typedFiles);
            if (parent.layer && typeof parent.layer.getFrameIndex === 'function') {
              var index = parent.layer.getFrameIndex(window.name);
              parent.layer.close(index);
            }
          } else if (parent !== window) {
            // The rebuilt design editor hosts this page in a native dialog.
            // postMessage keeps the old callback API intact while allowing
            // the parent to receive a trusted media-library selection.
            parent.postMessage({ type: 'beikeshop:file-manager-selection', files: typedFiles }, window.location.origin);
          }
        },

        deleteFile() {
          this.$confirm('{{ __('admin/file_manager.confirm_delete_file') }}', '{{ __('common.text_hint') }}', {
            type: 'warning'
          }).then(() => {
            const selectImageIndex = this.selectImageIndex;
            // 获取images中下标与selectImageIndex相同的图片
            const images = this.images.filter(e => selectImageIndex.includes(this.images.indexOf(e)));
            // images 取 path 组成数组 然后用 | 分割成字符串
            const files = images.map(e => e.name);

            this.loading = true;

            $http.delete('file_manager/files', {
              path: this.folderCurrent,
              files: files
            }, {
              hload: true
            }).then((res) => {
              layer.msg(res.message)
              return this.loadData()
            }).finally(() => { this.loading = false; })
          }).catch(_ => {});
        },

        deleteFolder(node, data) {
          if (data.path) {
            this.$confirm('{{ __('admin/file_manager.confirm_delete_folder') }}', '{{ __('common.text_hint') }}', {
              type: 'warning'
            }).then(() => {
              $http.delete(`file_manager/directories`, {
                name: data.path
              }).then((res) => {
                layer.msg(res.message)
                this.$refs.tree.setCurrentKey(node.parent.data.path)
                this.folderCurrent = node.parent.data.path;
                this.$refs.tree.remove(data.path)
              }).finally(() => this.loadData())
            }).catch(_ => {});
          }
        },

        selectAll() {
          // 获取 this.images 中的 selected 是否全部为 true
          const isAllSelected = this.images.every(e => e.selected);
          this.images.map(e => e.selected = !isAllSelected)
        },

        downloadImages() {
          // 获取选中的图片
          const selectedImages = this.images.filter(e => e.selected);
          // 创建 a 标签
          selectedImages.forEach(e => {
            const a = document.createElement('a');
            // 设置 a 标签的 href 属性
            a.href = e.origin_url;
            // 设置 a 标签的 download 属性
            a.download = e.name;
            // 触发 a 标签的 click 事件
            a.click();
          });
        },

        viewImages() {
          const selectedImages = this.images.filter(e => e.selected);
          selectedImages.forEach(e => {
            window.open(e.origin_url, '_blank', 'noopener,noreferrer');
          });
        },

        async copyLink() {
          const selectedImages = this.images.filter(e => e.selected);
          const text = selectedImages.map(e => e.origin_url).join('\n');
          if (!text) return;
          try {
            if (!navigator.clipboard || !navigator.clipboard.writeText) throw new Error('Clipboard API unavailable');
            await navigator.clipboard.writeText(text);
          } catch (error) {
            const input = document.createElement('textarea');
            input.value = text;
            input.setAttribute('readonly', '');
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
          }
          layer.msg('{{ __('admin/file_manager.copy_success') }}')
        },

        openInputBox(type, node, data) {
          let fileSuffix, fileName = '';

          if (type == 'download') {
            const exportUrl = new URL('file_manager/export', document.getElementsByTagName('base')[0].href);
            exportUrl.searchParams.set('path', data.path);
            window.open(exportUrl.toString(), '_blank', 'noopener,noreferrer');
            return;
          }

          if (type == 'image') {
            const selected = this.images[this.selectImageIndex[0]];
            if (!selected) return;
            const image = selected.name;
            // 获取文件后缀
            fileSuffix = image.substring(image.lastIndexOf('.') + 1);
            // 获取文件名
            fileName = image.substring(0, image.lastIndexOf('.'));
          }

          this.$prompt('', type == 'addFolder' ? '{{ __('admin/file_manager.new_folder') }}' : '{{ __('admin/file_manager.rename') }}', {
            confirmButtonText: '{{ __('common.confirm') }}',
            cancelButtonText: '{{ __('common.cancel') }}',
            inputPattern: /^.+$/,
            closeOnClickModal: false,
            inputValue: type == 'image' ? fileName : (type == 'renameFolder' ? data.name : '{{ __('admin/file_manager.new_folder') }}'),
            inputErrorMessage: '{{ __('admin/file_manager.can_empty') }}'
          }).then(({
            value
          }) => {
            if (type == 'addFolder') {
              let fileAllPathName = data.path == '/' ? '/' + value : data.path + '/' + value;
              $http.post(`file_manager/directories`, {
                name: fileAllPathName
              }).then((res) => {
                layer.msg(res.message)
                const targetNode = node || this.$refs.tree?.getNode(data.path);
                if (targetNode) {
                  targetNode.expanded = true
                  this.$refs.tree.append({
                    name: value,
                    path: fileAllPathName,
                    leaf: true
                  }, targetNode);
                  this.updateDefaultExpandedKeys(targetNode.data, 'expand')
                } else {
                  this.loadDirectories();
                }
                this.$refs.tree.setCurrentKey(fileAllPathName)
                this.folderCurrent = fileAllPathName;
                this.image_page = 1
                sessionStorage.setItem('folderCurrent', this.folderCurrent);
                this.loadData();
              })
            }

            if (type == 'renameFolder') {
              this.folderCurrent = data.path;

              $http.post(`file_manager/rename`, {
                origin_name: data.path,
                new_name: value
              }).then((res) => {
                layer.msg(res.message)
                data.name = value;
                data.path = data.path.replace(/\/[^\/]*$/, '/' + value);
                this.folderCurrent = this.folderCurrent.replace(/\/[^\/]*$/, '/' + value);
                // 递归修改 data 内所有 children -> path 的对应 level = value
                this.changeChildren(data, node, value);
                this.loadData();
              })
            }

            if (type == 'image') {
              const selected = this.images[this.selectImageIndex[0]];
              if (!selected) return;
              const name = selected.name;
              const origin_name = this.folderCurrent == '/' ? '/' + name : this.folderCurrent + '/' + name;

              $http.post(`file_manager/rename`, {
                origin_name: origin_name,
                new_name: value + '.' + fileSuffix
              }).then((res) => {
                this.loadData();
                layer.msg(res.message)
              })
            }
          }).catch(() => {});
        },

        changeChildren(data, node, value) {
          if (data.children) {
            data.children.map(e => {
              if (e.path) {
                // 将字符串转换为数组
                let path = e.path.split('/')
                path[node.level - 1] = value
                // 将数组转换为字符串
                e.path = path.join('/')
              }

              if (e.children) {
                this.changeChildren(e, node, value)
              }
            })
          }
        }
        @stack('admin.file_manager.vue.method')
      },

      @hook('admin.file_manager.vue.options')
    })

  </script>

  @stack('admin.file_manager.script_footer')
</body>
</html>
