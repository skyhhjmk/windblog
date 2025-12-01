/**
 * 媒体库管理系统
 * 使用原生JavaScript + Fetch API实现
 */
class MediaLibrary {
    constructor() {
        this.currentPage = 1;
        this.pageSize = 24;
        this.totalCount = 0;
        this.selectedItems = [];
        this.mediaList = [];
        this.isLoading = false;
        this.apiBase = '/app/admin/media/';

        this.init();
    }

    init() {
        this.bindEvents();
        this.loadMediaList();
    }

    bindEvents() {
        // 搜索表单
        document.getElementById('search-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.currentPage = 1;
            this.loadMediaList();
        });

        // 重置按钮
        document.getElementById('reset-btn').addEventListener('click', () => {
            document.getElementById('search-input').value = '';
            document.getElementById('type-filter').value = '';
            this.currentPage = 1;
            this.loadMediaList();
        });

        // 上传按钮
        document.getElementById('upload-btn').addEventListener('click', () => {
            this.showUploadModal();
        });

        // 批量删除按钮
        document.getElementById('batch-delete-btn').addEventListener('click', () => {
            this.batchDelete();
        });

        // 全选按钮
        document.getElementById('select-all-btn').addEventListener('click', () => {
            this.toggleSelectAll();
        });

        // 刷新按钮
        document.getElementById('refresh-btn').addEventListener('click', () => {
            this.loadMediaList();
        });

        // 上传模态框事件
        this.bindUploadModalEvents();

        // 编辑模态框事件
        this.bindEditModalEvents();

        // 预览模态框事件
        this.bindPreviewModalEvents();
    }

    bindUploadModalEvents() {
        const uploadModal = document.getElementById('upload-modal');
        const uploadArea = document.getElementById('upload-area');
        const fileInput = document.getElementById('file-input');
        const fileList = document.getElementById('file-list');

        // 关闭模态框
        document.getElementById('close-upload-modal').addEventListener('click', () => {
            this.hideUploadModal();
        });

        document.getElementById('cancel-upload').addEventListener('click', () => {
            this.hideUploadModal();
        });

        // 点击上传区域选择文件
        uploadArea.addEventListener('click', () => {
            fileInput.click();
        });

        // 文件选择
        fileInput.addEventListener('change', (e) => {
            this.handleFileSelect(e.target.files);
        });

        // 拖拽上传
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            this.handleFileSelect(e.dataTransfer.files);
        });

        // 确认上传
        document.getElementById('confirm-upload').addEventListener('click', () => {
            this.uploadFiles();
        });

        // 模态框外点击关闭
        uploadModal.addEventListener('click', (e) => {
            if (e.target === uploadModal) {
                this.hideUploadModal();
            }
        });
    }

    bindEditModalEvents() {
        const editModal = document.getElementById('edit-modal');

        // 关闭模态框
        document.getElementById('close-edit-modal').addEventListener('click', () => {
            this.hideEditModal();
        });

        document.getElementById('cancel-edit').addEventListener('click', () => {
            this.hideEditModal();
        });

        // 确认编辑
        document.getElementById('confirm-edit').addEventListener('click', () => {
            this.saveEdit();
        });

        // 模态框外点击关闭
        editModal.addEventListener('click', (e) => {
            if (e.target === editModal) {
                this.hideEditModal();
            }
        });
    }

    bindPreviewModalEvents() {
        const previewModal = document.getElementById('preview-modal');
        if (!previewModal) {
            console.warn('预览模态框元素不存在');
            return;
        }

        // 辅助函数：安全添加事件监听器
        const safeAddEventListener = (elementId, event, handler) => {
            const element = document.getElementById(elementId);
            if (element) {
                element.addEventListener(event, handler);
            } else {
                console.warn(`元素 ${elementId} 不存在，无法绑定事件`);
            }
        };

        // 关闭模态框
        safeAddEventListener('close-preview-modal', 'click', () => {
            this.hidePreviewModal();
        });

        // 下载按钮
        safeAddEventListener('download-btn', 'click', () => {
            this.downloadFile();
        });

        // 复制链接按钮
        safeAddEventListener('copy-url-btn', 'click', () => {
            this.copyFileUrl();
        });

        // 重新生成缩略图按钮
        safeAddEventListener('regenerate-thumb-btn', 'click', () => {
            this.regenerateThumbnail();
        });

        // 编辑文本按钮
        safeAddEventListener('edit-text-btn', 'click', () => {
            this.switchToTextEditMode(this.currentPreviewItem);
        });

        // 保存文本按钮
        safeAddEventListener('save-text-btn', 'click', () => {
            this.saveTextEdit();
        });

        // 取消文本编辑按钮
        safeAddEventListener('cancel-edit-btn', 'click', () => {
            this.cancelTextEdit();
        });

        // 引用文章折叠面板事件
        safeAddEventListener('toggle-references-btn', 'click', () => {
            this.toggleReferencesPanel();
        });

        // 模态框外点击关闭
        previewModal.addEventListener('click', (e) => {
            if (e.target === previewModal) {
                this.hidePreviewModal();
            }
        });
    }

    async loadMediaList() {
        if (this.isLoading) return;

        this.isLoading = true;
        this.showLoading(true);

        try {
            const searchValue = document.getElementById('search-input').value;
            const typeFilter = document.getElementById('type-filter').value;

            const params = new URLSearchParams({
                page: this.currentPage,
                limit: this.pageSize,
                search: searchValue,
                mime_type: typeFilter
            });

            const response = await fetch(`${this.apiBase}list?${params}`);
            const result = await response.json();

            if (result.code === 0) {
                this.mediaList = result.data;
                this.totalCount = result.total;
                this.renderMediaGrid();
                this.renderPagination();
                this.updateTotalCount();
            } else {
                this.showToast(result.msg || '加载失败', 'error');
            }
        } catch (error) {
            this.showToast('网络错误，请重试', 'error');
        } finally {
            this.isLoading = false;
            this.showLoading(false);
        }
    }

    renderMediaGrid() {
        const grid = document.getElementById('media-grid');

        if (!this.mediaList || this.mediaList.length === 0) {
            grid.innerHTML = `
                <div class="col-span-full text-center py-16">
                    <svg class="w-20 h-20 mx-auto mb-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">暂无媒体文件</h3>
                    <p class="text-gray-500 mb-6">开始上传您的第一个文件</p>
                    <button class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-all" onclick="mediaLibrary.showUploadModal()">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                        </svg>
                        上传文件
                    </button>
                </div>
            `;
            return;
        }

        const html = this.mediaList.map((item, index) => {
            const isSelected = this.selectedItems.some(selected => selected.id === item.id);
            const selectedClass = isSelected ? 'selected ring-2 ring-blue-500 bg-blue-50' : '';

            let thumbnailHtml = '';
            if (item.mime_type && item.mime_type.startsWith('image/')) {
                const imageSrc = item.thumb_path ? `/uploads/${item.thumb_path}` : `/uploads/${item.file_path}`;
                thumbnailHtml = `<img src="${imageSrc}" alt="${item.original_name}" class="w-full h-full object-cover">`;
            } else if (item.mime_type && item.mime_type.startsWith('video/')) {
                thumbnailHtml = `
                    <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-orange-100 to-orange-200">
                        <svg class="w-12 h-12 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                `;
            } else if (item.mime_type && item.mime_type.startsWith('audio/')) {
                thumbnailHtml = `
                    <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-teal-100 to-teal-200">
                        <svg class="w-12 h-12 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                        </svg>
                    </div>
                `;
            } else if (item.mime_type && item.mime_type.includes('pdf')) {
                thumbnailHtml = `
                    <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-red-100 to-red-200">
                        <svg class="w-12 h-12 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                `;
            } else {
                thumbnailHtml = `
                    <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-gray-100 to-gray-200">
                        <svg class="w-12 h-12 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                `;
            }

            return `
                <div class="media-item bg-white rounded-xl border border-gray-200 overflow-hidden cursor-pointer hover:shadow-lg transition-all duration-300 ${selectedClass} animate-fade-in"
                     data-id="${item.id}" style="animation-delay: ${index * 0.05}s">
                    <div class="aspect-square relative group">
                        ${thumbnailHtml}

                        <!-- 选择指示器 - 更显眼的版本 -->
                        ${isSelected ? `
                        <div class="absolute top-2 left-2">
                            <div class="w-10 h-10 bg-green-500 rounded-full flex items-center justify-center shadow-lg border-3 border-white ring-2 ring-green-300">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                        </div>
                        ` : ''}

                        <!-- 操作按钮 -->
                        <div class="absolute bottom-3 right-3 flex space-x-1 opacity-0 group-hover:opacity-100 transition-all duration-300 transform translate-y-2 group-hover:translate-y-0">
                            <button class="w-8 h-8 bg-white bg-opacity-90 backdrop-blur-sm rounded-full flex items-center justify-center hover:bg-blue-600 hover:text-white transition-all"
                                    onclick="event.stopPropagation(); mediaLibrary.previewMedia(${item.id})" title="预览">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                </svg>
                            </button>
                            <button class="w-8 h-8 bg-white bg-opacity-90 backdrop-blur-sm rounded-full flex items-center justify-center hover:bg-green-600 hover:text-white transition-all"
                                    onclick="event.stopPropagation(); mediaLibrary.editMedia(${item.id})" title="编辑">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                            </button>
                            <button class="w-8 h-8 bg-white bg-opacity-90 backdrop-blur-sm rounded-full flex items-center justify-center hover:bg-red-600 hover:text-white transition-all"
                                    onclick="event.stopPropagation(); mediaLibrary.deleteMedia(${item.id})" title="删除">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="p-4">
                        <h3 class="font-medium text-gray-900 truncate mb-1" title="${item.original_name}">${item.original_name}</h3>
                        <div class="flex items-center justify-between text-sm text-gray-500">
                            <span>${this.formatFileSize(item.file_size)}</span>
                            <span>${this.formatDate(item.created_at)}</span>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        grid.innerHTML = html;

        // 绑定媒体项点击事件
        grid.querySelectorAll('.media-item').forEach(item => {
            item.addEventListener('click', (e) => {
                if (e.target.closest('button')) return; // 忽略按钮点击
                const id = parseInt(item.dataset.id);
                this.toggleSelection(id);
            });
        });
    }

    toggleSelection(id) {
        const item = this.mediaList.find(media => media.id === id);
        if (!item) return;

        const index = this.selectedItems.findIndex(selected => selected.id === id);
        if (index > -1) {
            this.selectedItems.splice(index, 1);
        } else {
            this.selectedItems.push(item);
        }

        this.updateSelectionUI();
        // 只更新当前项的选择状态，而不是重新渲染整个网格
        this.updateMediaItemSelection(id);
    }

    toggleSelectAll() {
        if (this.selectedItems.length === this.mediaList.length) {
            // 取消全选
            this.selectedItems = [];
        } else {
            // 全选
            this.selectedItems = [...this.mediaList];
        }

        this.updateSelectionUI();
        // 只更新所有项的选择状态，而不是重新渲染整个网格
        this.mediaList.forEach(item => {
            this.updateMediaItemSelection(item.id);
        });
    }

    updateSelectionUI() {
        const count = this.selectedItems.length;
        const selectedCountElement = document.getElementById('selected-count');
        const batchDeleteBtn = document.getElementById('batch-delete-btn');
        const selectAllBtn = document.getElementById('select-all-btn');

        selectedCountElement.textContent = `已选择 ${count} 个文件`;

        if (count > 0) {
            batchDeleteBtn.disabled = false;
            batchDeleteBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        } else {
            batchDeleteBtn.disabled = true;
            batchDeleteBtn.classList.add('opacity-50', 'cursor-not-allowed');
        }

        // 更新全选按钮文本
        if (count === this.mediaList.length && this.mediaList.length > 0) {
            selectAllBtn.innerHTML = `
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                取消全选
            `;
        } else {
            selectAllBtn.innerHTML = `
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                全选
            `;
        }
    }

    /**
     * 更新单个媒体项的选择状态
     * @param {number} id - 媒体项ID
     */
    updateMediaItemSelection(id) {
        const mediaItem = document.querySelector(`.media-item[data-id="${id}"]`);
        if (!mediaItem) return;

        const isSelected = this.selectedItems.some(selected => selected.id === id);
        const checkIndicator = mediaItem.querySelector('.absolute.top-3.left-3 .w-6.h-6');

        if (isSelected) {
            // 添加选中样式
            mediaItem.classList.add('selected', 'ring-2', 'ring-blue-500', 'bg-blue-50');
            // 更新选择指示器
            if (checkIndicator) {
                checkIndicator.classList.add('bg-blue-600', 'border-blue-600');
                checkIndicator.innerHTML = `
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                `;
            }
        } else {
            // 移除选中样式
            mediaItem.classList.remove('selected', 'ring-2', 'ring-blue-500', 'bg-blue-50');
            // 更新选择指示器
            if (checkIndicator) {
                checkIndicator.classList.remove('bg-blue-600', 'border-blue-600');
                checkIndicator.innerHTML = '';
            }
        }
    }

    updateTotalCount() {
        document.getElementById('total-count').textContent = this.totalCount;
    }

    renderPagination() {
        const pagination = document.getElementById('pagination');
        const totalPages = Math.ceil(this.totalCount / this.pageSize);

        if (totalPages <= 1) {
            pagination.innerHTML = '';
            return;
        }

        let html = '';

        // 上一页
        if (this.currentPage > 1) {
            html += `
                <button class="px-3 py-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-all" onclick="mediaLibrary.goToPage(${this.currentPage - 1})">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </button>
            `;
        }

        // 页码
        const startPage = Math.max(1, this.currentPage - 2);
        const endPage = Math.min(totalPages, this.currentPage + 2);

        if (startPage > 1) {
            html += `<button class="px-3 py-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-all" onclick="mediaLibrary.goToPage(1)">1</button>`;
            if (startPage > 2) {
                html += `<span class="px-3 py-2 text-gray-400">...</span>`;
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === this.currentPage ? 'bg-blue-600 text-white' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100';
            html += `<button class="px-3 py-2 ${activeClass} rounded-lg transition-all" onclick="mediaLibrary.goToPage(${i})">${i}</button>`;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span class="px-3 py-2 text-gray-400">...</span>`;
            }
            html += `<button class="px-3 py-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-all" onclick="mediaLibrary.goToPage(${totalPages})">${totalPages}</button>`;
        }

        // 下一页
        if (this.currentPage < totalPages) {
            html += `
                <button class="px-3 py-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-all" onclick="mediaLibrary.goToPage(${this.currentPage + 1})">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>
            `;
        }

        pagination.innerHTML = html;
    }

    goToPage(page) {
        this.currentPage = page;
        this.loadMediaList();
    }

    // 上传相关方法
    showUploadModal() {
        document.getElementById('upload-modal').classList.remove('hidden');
        this.resetUploadForm();
    }

    hideUploadModal() {
        document.getElementById('upload-modal').classList.add('hidden');
        this.resetUploadForm();
    }

    resetUploadForm() {
        document.getElementById('upload-form').reset();
        document.getElementById('file-list').classList.add('hidden');
        document.getElementById('file-list').innerHTML = '';
        document.getElementById('file-input').value = '';
    }

    handleFileSelect(files) {
        if (!files || files.length === 0) return;

        const fileList = document.getElementById('file-list');
        fileList.classList.remove('hidden');

        let html = '';
        Array.from(files).forEach((file, index) => {
            const fileType = this.getFileTypeIcon(file.type);
            html += `
                <div class="flex items-center p-3 bg-gray-50 rounded-lg" data-index="${index}">
                    <div class="flex-shrink-0 mr-3">
                        ${fileType.icon}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900 truncate">${file.name}</p>
                        <p class="text-sm text-gray-500">${this.formatFileSize(file.size)} • ${fileType.type}</p>
                    </div>
                    <button type="button" class="flex-shrink-0 ml-3 text-gray-400 hover:text-red-600 transition-colors" onclick="this.parentElement.remove()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            `;
        });

        fileList.innerHTML = html;
    }

    getFileTypeIcon(mimeType) {
        if (mimeType.startsWith('image/')) {
            return {
                icon: `<div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                </div>`,
                type: '图片'
            };
        } else if (mimeType.startsWith('video/')) {
            return {
                icon: `<div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                </div>`,
                type: '视频'
            };
        } else if (mimeType.startsWith('audio/')) {
            return {
                icon: `<div class="w-10 h-10 bg-teal-100 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                    </svg>
                </div>`,
                type: '音频'
            };
        } else {
            return {
                icon: `<div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </div>`,
                type: '文档'
            };
        }
    }

    async uploadFiles() {
        const fileInput = document.getElementById('file-input');
        const files = fileInput.files;

        if (!files || files.length === 0) {
            this.showToast('请选择文件', 'error');
            return;
        }

        const altText = document.getElementById('alt-text').value;
        const caption = document.getElementById('caption').value;
        const description = document.getElementById('description').value;

        // 显示上传进度
        this.showUploadProgress();

        try {
            const uploadPromises = Array.from(files).map(file => this.uploadSingleFile(file, {
                alt_text: altText,
                caption: caption,
                description: description
            }));

            const results = await Promise.all(uploadPromises);
            const successCount = results.filter(result => result.success).length;
            const failCount = results.length - successCount;

            if (successCount > 0) {
                this.showToast(`成功上传 ${successCount} 个文件${failCount > 0 ? `，${failCount} 个失败` : ''}`, 'success');
                this.hideUploadModal();
                this.loadMediaList();
            } else {
                this.showToast('上传失败', 'error');
            }
        } catch (error) {
            this.showToast('上传失败：' + error.message, 'error');
        } finally {
            this.hideUploadProgress();
        }
    }

    async uploadSingleFile(file, metadata) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('alt_text', metadata.alt_text);
        formData.append('caption', metadata.caption);
        formData.append('description', metadata.description);

        try {
            const response = await fetch(`${this.apiBase}upload`, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            return { success: result.code === 0, result };
        } catch (error) {
            return { success: false, error: error.message };
        }
    }

    showUploadProgress() {
        // 可以在这里显示上传进度条
        this.showLoading(true);
    }

    hideUploadProgress() {
        this.showLoading(false);
    }

    // 编辑相关方法
    editMedia(id) {
        const item = this.mediaList.find(media => media.id === id);
        if (!item) return;

        document.getElementById('edit-id').value = item.id;
        document.getElementById('edit-alt-text').value = item.alt_text || '';
        document.getElementById('edit-caption').value = item.caption || '';
        document.getElementById('edit-description').value = item.description || '';

        document.getElementById('edit-modal').classList.remove('hidden');
    }

    hideEditModal() {
        document.getElementById('edit-modal').classList.add('hidden');
    }

    async saveEdit() {
        const id = document.getElementById('edit-id').value;
        const altText = document.getElementById('edit-alt-text').value;
        const caption = document.getElementById('edit-caption').value;
        const description = document.getElementById('edit-description').value;

        try {
            const response = await fetch(`${this.apiBase}update/${id}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    alt_text: altText,
                    caption: caption,
                    description: description
                })
            });

            const result = await response.json();

            if (result.code === 0) {
                this.showToast('保存成功', 'success');
                this.hideEditModal();
                this.loadMediaList();
            } else {
                this.showToast(result.msg || '保存失败', 'error');
            }
        } catch (error) {
            this.showToast('保存失败：' + error.message, 'error');
        }
    }

    // 预览相关方法
    previewMedia(id) {
        const item = this.mediaList.find(media => media.id === id);
        if (!item) return;

        this.currentPreviewItem = item;

        // 设置标题
        document.getElementById('preview-title').textContent = item.original_name;

        // 设置预览内容
        this.renderPreviewContent(item);

        // 设置文件信息
        this.renderPreviewInfo(item);

        // 显示/隐藏文本编辑按钮
        this.toggleTextEditButtons(item);

        // 显示模态框
        document.getElementById('preview-modal').classList.remove('hidden');
    }

    /**
     * 显示/隐藏文本编辑按钮
     * @param {Object} item - 媒体项
     */
    toggleTextEditButtons(item) {
        const editTextBtn = document.getElementById('edit-text-btn');
        const saveTextBtn = document.getElementById('save-text-btn');
        const cancelTextBtn = document.getElementById('cancel-edit-btn');

        // 检查元素是否存在
        if (!editTextBtn || !saveTextBtn || !cancelTextBtn) {
            console.warn('文本编辑按钮元素不存在');
            return;
        }

        // 检查是否为可编辑的文本文件
        const isEditableText = this.isEditableTextFile(item);

        if (isEditableText) {
            editTextBtn.classList.remove('hidden');
            saveTextBtn.classList.add('hidden');
            cancelTextBtn.classList.add('hidden');
        } else {
            editTextBtn.classList.add('hidden');
            saveTextBtn.classList.add('hidden');
            cancelTextBtn.classList.add('hidden');
        }
    }

    /**
     * 检查是否为可编辑的文本文件
     * @param {Object} item - 媒体项
     * @returns {boolean}
     */
    isEditableTextFile(item) {
        const editableTypes = ['text/plain', 'application/json', 'text/css', 'text/javascript', 'text/xml', 'text/html', 'text/markdown'];
        return editableTypes.some(type => item.mime_type && item.mime_type.includes(type));
    }

    renderPreviewContent(item) {
        const previewContent = document.getElementById('preview-content');
        const fileUrl = `/uploads/${item.file_path}`;

        // 隐藏文本编辑器容器，显示普通预览容器
        document.getElementById('text-editor-container').classList.add('hidden');
        document.getElementById('normal-preview-container').classList.remove('hidden');

        if (item.mime_type && item.mime_type.startsWith('image/')) {
            previewContent.innerHTML = `
                <img src="${fileUrl}" alt="${item.original_name}" class="max-w-full max-h-full object-contain rounded-lg shadow-lg">
            `;
        } else if (item.mime_type && item.mime_type.startsWith('video/')) {
            previewContent.innerHTML = `
                <video controls class="max-w-full max-h-full rounded-lg shadow-lg">
                    <source src="${fileUrl}" type="${item.mime_type}">
                    您的浏览器不支持视频播放。
                </video>
            `;
        } else if (item.mime_type && item.mime_type.startsWith('audio/')) {
            previewContent.innerHTML = `
                <div class="text-center">
                    <div class="w-32 h-32 bg-gradient-to-br from-teal-100 to-teal-200 rounded-full flex items-center justify-center mx-auto mb-6">
                        <svg class="w-16 h-16 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                        </svg>
                    </div>
                    <audio controls class="w-full max-w-md">
                        <source src="${fileUrl}" type="${item.mime_type}">
                        您的浏览器不支持音频播放。
                    </audio>
                </div>
            `;
        } else if (item.mime_type && item.mime_type.includes('pdf')) {
            previewContent.innerHTML = `
                <embed src="${fileUrl}" type="application/pdf" class="w-full h-full min-h-[500px] rounded-lg">
            `;
        } else if (this.isEditableTextFile(item)) {
            // 文本文件预览
            this.previewTextFile(item);
        } else {
            previewContent.innerHTML = `
                <div class="text-center py-16">
                    <div class="w-32 h-32 bg-gradient-to-br from-gray-100 to-gray-200 rounded-full flex items-center justify-center mx-auto mb-6">
                        <svg class="w-16 h-16 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                    <p class="text-gray-500">无法预览此文件类型</p>
                    <p class="text-sm text-gray-400 mt-2">请下载文件查看内容</p>
                </div>
            `;
        }
    }

    /**
     * 预览文本文件
     * @param {Object} item - 媒体项
     */
    async previewTextFile(item) {
        const previewContent = document.getElementById('preview-content');

        try {
            const response = await fetch(`/app/admin/media/previewText/${item.id}`);
            const result = await response.json();

            if (result.code === 0) {
                previewContent.innerHTML = `
                    <div class="h-full flex flex-col">
                        <div class="flex-1 overflow-auto">
                            <pre class="whitespace-pre-wrap break-words font-mono text-sm p-4 bg-gray-50 rounded-lg max-h-full">${this.escapeHtml(result.data.content)}</pre>
                        </div>
                        <div class="mt-4 text-xs text-gray-500">
                            文件大小: ${this.formatFileSize(item.file_size)} | 字符数: ${result.data.content.length}
                        </div>
                    </div>
                `;
            } else {
                previewContent.innerHTML = `
                    <div class="text-center py-16">
                        <p class="text-gray-500">无法读取文件内容</p>
                        <p class="text-sm text-gray-400 mt-2">${result.msg || '文件可能过大或格式不支持'}</p>
                    </div>
                `;
            }
        } catch (error) {
            previewContent.innerHTML = `
                <div class="text-center py-16">
                    <p class="text-gray-500">加载文件内容失败</p>
                    <p class="text-sm text-gray-400 mt-2">${error.message}</p>
                </div>
            `;
        }
    }

    /**
     * HTML转义
     * @param {string} text - 要转义的文本
     * @returns {string}
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * 初始化Monaco Editor
     * @param {string} content - 编辑器初始内容
     * @param {string} language - 编辑器语言模式
     */
    initMonacoEditor(content = '', language = 'plaintext') {
        // 检查Monaco Editor是否已加载
        if (!window.monaco) {
            console.error('Monaco Editor未加载，请检查CDN资源是否正确引入');
            this.showToast('代码编辑器加载失败，请刷新页面重试', 'error');
            return;
        }

        const editorContainer = document.getElementById('monaco-editor');
        if (!editorContainer) {
            console.error('Monaco Editor容器未找到');
            return;
        }

        // 清空容器
        editorContainer.innerHTML = '';

        // 确保容器有正确的尺寸样式
        editorContainer.style.width = '100%';
        editorContainer.style.height = '100%';

        try {
            // 创建编辑器实例
            this.monacoEditor = monaco.editor.create(editorContainer, {
                value: content,
                language: language,
                theme: 'vs-light',
                fontSize: 14,
                wordWrap: 'on',
                minimap: { enabled: false },
                automaticLayout: true,
                scrollBeyondLastLine: false,
                renderLineHighlight: 'all',
                lineNumbers: 'on',
                folding: true,
                lineDecorationsWidth: 10,
                lineNumbersMinChars: 3,
                scrollbar: {
                    vertical: 'visible',
                    horizontal: 'visible',
                    useShadows: false
                }
            });

            // 监听编辑器内容变化
            this.monacoEditor.onDidChangeModelContent(() => {
                this.isContentModified = true;
            });

            console.log('Monaco Editor初始化成功');
        } catch (error) {
            console.error('Monaco Editor初始化失败:', error);
            this.showToast('代码编辑器初始化失败: ' + error.message, 'error');
        }
    }

    /**
     * 销毁Monaco Editor实例
     */
    destroyMonacoEditor() {
        if (this.monacoEditor) {
            this.monacoEditor.dispose();
            this.monacoEditor = null;
        }
        this.isContentModified = false;
        this.currentEditingItem = null;
    }

    /**
     * 切换到文本编辑模式
     * @param {Object} item - 媒体项
     */
    async switchToTextEditMode(item) {
        try {
            const response = await fetch(`/app/admin/media/previewText/${item.id}`);
            const result = await response.json();

            if (result.code === 0) {
                // 隐藏普通预览容器，显示文本编辑器容器
                document.getElementById('normal-preview-container').classList.add('hidden');
                document.getElementById('text-editor-container').classList.remove('hidden');

                // 设置编辑器语言
                const language = this.getLanguageByFileType(item.original_name);

                // 初始化Monaco Editor
                this.initMonacoEditor(result.data.content, language);

                // 保存当前编辑项
                this.currentEditingItem = item;

                // 显示保存和取消按钮
                document.getElementById('save-text-btn').classList.remove('hidden');
                document.getElementById('cancel-edit-btn').classList.remove('hidden');
                document.getElementById('edit-text-btn').classList.add('hidden');

            } else {
                this.showToast(result.msg || '无法读取文件内容', 'error');
            }
        } catch (error) {
            this.showToast('加载文件内容失败: ' + error.message, 'error');
        }
    }

    /**
     * 根据文件名获取语言模式
     * @param {string} filename - 文件名
     * @returns {string}
     */
    getLanguageByFileType(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        const languageMap = {
            'js': 'javascript',
            'jsx': 'javascript',
            'ts': 'typescript',
            'tsx': 'typescript',
            'html': 'html',
            'htm': 'html',
            'css': 'css',
            'scss': 'scss',
            'less': 'less',
            'json': 'json',
            'xml': 'xml',
            'md': 'markdown',
            'markdown': 'markdown',
            'php': 'php',
            'py': 'python',
            'java': 'java',
            'c': 'c',
            'cpp': 'cpp',
            'cs': 'csharp',
            'go': 'go',
            'rs': 'rust',
            'sql': 'sql',
            'sh': 'shell',
            'bat': 'bat',
            'yaml': 'yaml',
            'yml': 'yaml',
            'txt': 'plaintext'
        };

        return languageMap[ext] || 'plaintext';
    }

    /**
     * 保存文本编辑内容
     */
    async saveTextEdit() {
        if (!this.monacoEditor || !this.currentEditingItem) {
            this.showToast('没有可保存的内容', 'error');
            return;
        }

        const content = this.monacoEditor.getValue();

        try {
            this.showLoading(true);

            const response = await fetch(`/app/admin/media/saveText/${this.currentEditingItem.id}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({ content: content })
            });

            const result = await response.json();

            if (result.code === 0) {
                this.showToast('文件保存成功', 'success');
                this.isContentModified = false;

                // 保存当前编辑项引用
                const savedItem = this.currentEditingItem;

                // 切换回预览模式
                this.cancelTextEdit();

                // 重新加载预览内容
                this.previewTextFile(savedItem);

            } else {
                this.showToast(result.msg || '保存失败', 'error');
            }
        } catch (error) {
            this.showToast('保存失败: ' + error.message, 'error');
        } finally {
            this.showLoading(false);
        }
    }

    /**
     * 取消文本编辑
     */
    cancelTextEdit() {
        // 销毁编辑器
        this.destroyMonacoEditor();

        // 显示普通预览容器，隐藏文本编辑器容器
        document.getElementById('text-editor-container').classList.add('hidden');
        document.getElementById('normal-preview-container').classList.remove('hidden');

        // 显示编辑按钮，隐藏保存和取消按钮
        document.getElementById('edit-text-btn').classList.remove('hidden');
        document.getElementById('save-text-btn').classList.add('hidden');
        document.getElementById('cancel-edit-btn').classList.add('hidden');
    }

    renderPreviewInfo(item) {
        const previewInfo = document.getElementById('preview-info');
        const regenerateBtn = document.getElementById('regenerate-thumb-btn');

        // 显示/隐藏重新生成缩略图按钮
        if (item.mime_type && item.mime_type.startsWith('image/')) {
            regenerateBtn.classList.remove('hidden');
        } else {
            regenerateBtn.classList.add('hidden');
        }

        previewInfo.innerHTML = `
            <div class="space-y-3">
                <div>
                    <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">文件名</dt>
                    <dd class="mt-1 text-sm text-gray-900 break-all">${item.filename}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">原始名称</dt>
                    <dd class="mt-1 text-sm text-gray-900 break-all">${item.original_name}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">文件大小</dt>
                    <dd class="mt-1 text-sm text-gray-900">${this.formatFileSize(item.file_size)}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">文件类型</dt>
                    <dd class="mt-1 text-sm text-gray-900">${item.mime_type}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">上传时间</dt>
                    <dd class="mt-1 text-sm text-gray-900">${this.formatDate(item.created_at)}</dd>
                </div>
                ${item.alt_text ? `
                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">替代文本</dt>
                        <dd class="mt-1 text-sm text-gray-900">${item.alt_text}</dd>
                    </div>
                ` : ''}
                ${item.caption ? `
                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">说明文字</dt>
                        <dd class="mt-1 text-sm text-gray-900">${item.caption}</dd>
                    </div>
                ` : ''}
                ${item.description ? `
                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">描述</dt>
                        <dd class="mt-1 text-sm text-gray-900">${item.description}</dd>
                    </div>
                ` : ''}
            </div>
        `;
    }

    /**
     * 切换引用文章面板的显示状态
     */
    toggleReferencesPanel() {
        const container = document.getElementById('references-container');
        const icon = document.getElementById('references-icon');

        if (container.classList.contains('hidden')) {
            // 展开面板
            container.classList.remove('hidden');
            icon.style.transform = 'rotate(180deg)';
            // 加载引用文章列表
            this.loadReferences();
        } else {
            // 折叠面板
            container.classList.add('hidden');
            icon.style.transform = 'rotate(0deg)';
        }
    }

    /**
     * 加载引用文章列表
     */
    async loadReferences() {
        if (!this.currentPreviewItem) return;

        const content = document.getElementById('references-content');
        content.innerHTML = '<div class="text-sm text-gray-500">加载中...</div>';

        try {
            const response = await fetch(`${this.apiBase}references/${this.currentPreviewItem.id}`);
            const result = await response.json();

            if (result.code === 0) {
                const posts = result.data.posts;
                if (posts && posts.length > 0) {
                    const html = posts.map(post => {
                        const postUrl = `/post/${post.slug}`; // 文章URL，根据实际路由调整
                        return `
                            <div class="flex items-center">
                                <svg class="w-4 h-4 text-gray-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"></path>
                                </svg>
                                <a href="${postUrl}" target="_blank" rel="noopener noreferrer" class="text-sm text-blue-600 hover:text-blue-800 hover:underline">
                                    ${post.title}
                                </a>
                            </div>
                        `;
                    }).join('');
                    content.innerHTML = html;
                } else {
                    content.innerHTML = '<div class="text-sm text-gray-500">暂无引用文章</div>';
                }
            } else {
                content.innerHTML = `<div class="text-sm text-red-600">加载失败：${result.msg}</div>`;
            }
        } catch (error) {
            content.innerHTML = `<div class="text-sm text-red-600">加载失败：${error.message}</div>`;
        }
    }

    hidePreviewModal() {
        document.getElementById('preview-modal').classList.add('hidden');
        this.currentPreviewItem = null;

        // 重置引用文章面板
        const container = document.getElementById('references-container');
        const icon = document.getElementById('references-icon');
        if (container) {
            container.classList.add('hidden');
        }
        if (icon) {
            icon.style.transform = 'rotate(0deg)';
        }
    }

    downloadFile() {
        if (!this.currentPreviewItem) return;

        const link = document.createElement('a');
        link.href = `/uploads/${this.currentPreviewItem.file_path}`;
        link.download = this.currentPreviewItem.original_name;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    async copyFileUrl() {
        if (!this.currentPreviewItem) return;

        const url = `${window.location.origin}/uploads/${this.currentPreviewItem.file_path}`;

        try {
            await navigator.clipboard.writeText(url);
            this.showToast('链接已复制到剪贴板', 'success');
        } catch (error) {
            // 降级方案
            const textArea = document.createElement('textarea');
            textArea.value = url;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            this.showToast('链接已复制到剪贴板', 'success');
        }
    }

    async regenerateThumbnail() {
        if (!this.currentPreviewItem) return;

        try {
            const response = await fetch(`${this.apiBase}regenerateThumbnail/${this.currentPreviewItem.id}`, {
                method: 'POST'
            });

            const result = await response.json();

            if (result.code === 0) {
                this.showToast('缩略图重新生成成功', 'success');
                this.loadMediaList();
            } else {
                this.showToast(result.msg || '缩略图生成失败', 'error');
            }
        } catch (error) {
            this.showToast('缩略图生成失败：' + error.message, 'error');
        }
    }

    // 删除相关方法
    async deleteMedia(id) {
        const item = this.mediaList.find(media => media.id === id);
        if (!item) return;

        if (!confirm(`确定要删除文件 "${item.original_name}" 吗？`)) {
            return;
        }

        try {
            const response = await fetch(`${this.apiBase}remove/${id}`, {
                method: 'DELETE'
            });

            const result = await response.json();

            if (result.code === 0) {
                this.showToast('文件删除成功', 'success');
                this.loadMediaList();
            } else {
                this.showToast(result.msg || '删除失败', 'error');
            }
        } catch (error) {
            this.showToast('删除失败：' + error.message, 'error');
        }
    }

    async batchDelete() {
        if (this.selectedItems.length === 0) {
            this.showToast('请选择要删除的文件', 'error');
            return;
        }

        if (!confirm(`确定要删除选中的 ${this.selectedItems.length} 个文件吗？`)) {
            return;
        }

        try {
            const ids = this.selectedItems.map(item => item.id).join(',');
            const response = await fetch(`${this.apiBase}batchRemove/${ids}`, {
                method: 'DELETE'
            });

            const result = await response.json();

            if (result.code === 0) {
                this.showToast(`成功删除 ${this.selectedItems.length} 个文件`, 'success');
                this.selectedItems = [];
                this.updateSelectionUI();
                this.loadMediaList();
            } else {
                this.showToast(result.msg || '批量删除失败', 'error');
            }
        } catch (error) {
            this.showToast('批量删除失败：' + error.message, 'error');
        }
    }

    // 工具方法
    formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    formatDate(dateString) {
        if (!dateString) return '未知';
        const date = new Date(dateString);
        const now = new Date();
        const diffTime = Math.abs(now - date);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

        if (diffDays <= 1) {
            return '今天';
        } else if (diffDays <= 7) {
            return diffDays + '天前';
        } else {
            return date.getFullYear() + '-' +
                String(date.getMonth() + 1).padStart(2, '0') + '-' +
                String(date.getDate()).padStart(2, '0');
        }
    }

    showLoading(show) {
        const overlay = document.getElementById('loading-overlay');
        if (show) {
            overlay.classList.remove('hidden');
        } else {
            overlay.classList.add('hidden');
        }
    }

    showToast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');

        const bgColor = {
            success: 'bg-green-500',
            error: 'bg-red-500',
            warning: 'bg-yellow-500',
            info: 'bg-blue-500'
        }[type] || 'bg-blue-500';

        const icon = {
            success: `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>`,
            error: `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>`,
            warning: `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
            </svg>`,
            info: `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>`
        }[type] || '';

        toast.className = `toast ${bgColor} text-white px-4 py-3 rounded-lg shadow-lg flex items-center space-x-2 mb-2`;
        toast.innerHTML = `
            ${icon}
            <span>${message}</span>
            <button class="ml-2 hover:bg-white hover:bg-opacity-20 rounded p-1 transition-colors" onclick="this.parentElement.remove()">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        `;

        container.appendChild(toast);

        // 自动移除
        setTimeout(() => {
            if (toast.parentElement) {
                toast.remove();
            }
        }, 5000);
    }
}

// 初始化媒体库
document.addEventListener('DOMContentLoaded', () => {
    window.mediaLibrary = new MediaLibrary();
});
