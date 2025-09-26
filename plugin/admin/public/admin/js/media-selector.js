/**
 * 媒体选择器
 * 用于在其他页面中选择媒体文件
 */
class MediaSelector {
    constructor(options = {}) {
        this.options = {
            multiple: false,
            allowedTypes: [], // 允许的文件类型，如 ['image/*', 'video/*']
            maxSelection: 1,
            onSelect: null,
            onCancel: null,
            ...options
        };
        
        this.currentPage = 1;
        this.pageSize = 20;
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
        this.updateSelectionUI();
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

        // 确认选择按钮
        document.getElementById('confirm-select-btn').addEventListener('click', () => {
            this.confirmSelection();
        });

        // 取消按钮
        document.getElementById('cancel-btn').addEventListener('click', () => {
            this.cancelSelection();
        });

        // 清空选择按钮
        document.getElementById('clear-selection-btn').addEventListener('click', () => {
            this.clearSelection();
        });

        // 上传模态框事件
        this.bindUploadModalEvents();
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
                    <button class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-all" onclick="mediaSelector.showUploadModal()">
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
            // 检查文件类型是否允许
            const isAllowed = this.isFileTypeAllowed(item.mime_type);
            const isSelected = this.selectedItems.some(selected => selected.id === item.id);
            const selectedClass = isSelected ? 'selected ring-2 ring-blue-500 bg-blue-50' : '';
            const disabledClass = !isAllowed ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer';
            
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
                <div class="media-item bg-white rounded-xl border border-gray-200 overflow-hidden hover:shadow-lg transition-all duration-300 ${selectedClass} ${disabledClass} animate-fade-in" 
                     data-id="${item.id}" data-allowed="${isAllowed}" style="animation-delay: ${index * 0.05}s">
                    <div class="aspect-square relative group">
                        ${thumbnailHtml}
                        
                        <!-- 选择指示器 -->
                        <div class="absolute top-3 left-3">
                            <div class="w-6 h-6 rounded-full border-2 border-white bg-white bg-opacity-20 backdrop-blur-sm flex items-center justify-center transition-all ${isSelected ? 'bg-blue-600 border-blue-600' : 'hover:bg-opacity-40'}">
                                ${isSelected ? `
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                ` : ''}
                            </div>
                        </div>
                        
                        ${!isAllowed ? `
                            <div class="absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center">
                                <div class="text-white text-center">
                                    <svg class="w-8 h-8 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636m12.728 12.728L18.364 5.636M5.636 18.364l12.728-12.728"></path>
                                    </svg>
                                    <p class="text-xs">不支持的类型</p>
                                </div>
                            </div>
                        ` : ''}
                    </div>
                    
                    <div class="p-3">
                        <h3 class="font-medium text-gray-900 truncate text-sm mb-1" title="${item.original_name}">${item.original_name}</h3>
                        <div class="flex items-center justify-between text-xs text-gray-500">
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
                const id = parseInt(item.dataset.id);
                const isAllowed = item.dataset.allowed === 'true';
                
                if (!isAllowed) {
                    this.showToast('不支持的文件类型', 'warning');
                    return;
                }
                
                this.toggleSelection(id);
            });
        });
    }

    isFileTypeAllowed(mimeType) {
        if (!this.options.allowedTypes || this.options.allowedTypes.length === 0) {
            return true; // 如果没有限制，则允许所有类型
        }

        return this.options.allowedTypes.some(allowedType => {
            if (allowedType.endsWith('/*')) {
                const prefix = allowedType.slice(0, -2);
                return mimeType.startsWith(prefix);
            }
            return mimeType === allowedType;
        });
    }

    toggleSelection(id) {
        const item = this.mediaList.find(media => media.id === id);
        if (!item) return;

        const index = this.selectedItems.findIndex(selected => selected.id === id);
        
        if (index > -1) {
            // 取消选择
            this.selectedItems.splice(index, 1);
        } else {
            // 选择文件
            if (!this.options.multiple) {
                // 单选模式，清空之前的选择
                this.selectedItems = [item];
            } else {
                // 多选模式，检查是否超过最大选择数量
                if (this.selectedItems.length >= this.options.maxSelection) {
                    this.showToast(`最多只能选择 ${this.options.maxSelection} 个文件`, 'warning');
                    return;
                }
                this.selectedItems.push(item);
            }
        }

        this.updateSelectionUI();
        this.renderMediaGrid(); // 重新渲染以更新选择状态
    }

    clearSelection() {
        this.selectedItems = [];
        this.updateSelectionUI();
        this.renderMediaGrid();
    }

    updateSelectionUI() {
        const count = this.selectedItems.length;
        const selectedCountElement = document.getElementById('selected-count');
        const confirmBtn = document.getElementById('confirm-select-btn');
        const clearBtn = document.getElementById('clear-selection-btn');

        selectedCountElement.textContent = `已选择 ${count} 个文件`;

        if (count > 0) {
            confirmBtn.disabled = false;
            confirmBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            clearBtn.disabled = false;
            clearBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        } else {
            confirmBtn.disabled = true;
            confirmBtn.classList.add('opacity-50', 'cursor-not-allowed');
            clearBtn.disabled = true;
            clearBtn.classList.add('opacity-50', 'cursor-not-allowed');
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
                <button class="px-3 py-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-all" onclick="mediaSelector.goToPage(${this.currentPage - 1})">
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
            html += `<button class="px-3 py-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-all" onclick="mediaSelector.goToPage(1)">1</button>`;
            if (startPage > 2) {
                html += `<span class="px-3 py-2 text-gray-400">...</span>`;
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === this.currentPage ? 'bg-blue-600 text-white' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100';
            html += `<button class="px-3 py-2 ${activeClass} rounded-lg transition-all" onclick="mediaSelector.goToPage(${i})">${i}</button>`;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span class="px-3 py-2 text-gray-400">...</span>`;
            }
            html += `<button class="px-3 py-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-all" onclick="mediaSelector.goToPage(${totalPages})">${totalPages}</button>`;
        }

        // 下一页
        if (this.currentPage < totalPages) {
            html += `
                <button class="px-3 py-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-all" onclick="mediaSelector.goToPage(${this.currentPage + 1})">
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

    confirmSelection() {
        if (this.selectedItems.length === 0) {
            this.showToast('请选择文件', 'warning');
            return;
        }

        const result = this.options.multiple ? this.selectedItems : this.selectedItems[0];
        let selectionSuccess = false;
        
        // 调试信息
        console.log('MediaSelector (JS): 尝试完成选择', {result: result});
        console.log('MediaSelector (JS): 窗口关系检查', {
            hasParent: window.parent && window.parent !== window,
            hasOpener: window.opener && window.opener !== window
        });

        try {
            // 1. 尝试使用options.onSelect回调（最高优先级）
            if (this.options.onSelect && typeof this.options.onSelect === 'function') {
                console.log('MediaSelector (JS): 使用options.onSelect回调');
                this.options.onSelect(result);
                selectionSuccess = true;
                return;
            }

            // 2. 检查父窗口是否有selectMedia函数
            if (window.parent && window.parent !== window && typeof window.parent.selectMedia === 'function') {
                console.log('MediaSelector (JS): 调用window.parent.selectMedia');
                window.parent.selectMedia(result);
                selectionSuccess = true;
                return;
            }

            // 3. 检查opener窗口是否有selectMedia函数
            if (window.opener && window.opener !== window && typeof window.opener.selectMedia === 'function') {
                console.log('MediaSelector (JS): 调用window.opener.selectMedia');
                window.opener.selectMedia(result);
                selectionSuccess = true;
                // 延迟关闭，确保回调执行完成
                if (this.options.autoClose !== false) {
                    setTimeout(() => {
                        if (window.opener && !window.opener.closed) {
                            window.close();
                        }
                    }, 100);
                }
                return;
            }

            // 4. 向父窗口发送postMessage
            if (window.parent && window.parent !== window) {
                console.log('MediaSelector (JS): 向window.parent发送postMessage');
                window.parent.postMessage({
                    type: 'mediaSelected',
                    data: result
                }, '*');
                selectionSuccess = true;
            }
            
            // 5. 向opener窗口发送postMessage
            else if (window.opener && window.opener !== window) {
                console.log('MediaSelector (JS): 向window.opener发送postMessage');
                window.opener.postMessage({
                    type: 'mediaSelected',
                    data: result
                }, '*');
                selectionSuccess = true;
                // 延迟关闭，确保消息发送完成
                if (this.options.autoClose !== false) {
                    setTimeout(() => {
                        if (window.opener && !window.opener.closed) {
                            window.close();
                        }
                    }, 100);
                }
            }
            
            // 如果所有方法都失败，显示错误
            if (!selectionSuccess) {
                console.error('MediaSelector (JS): 所有选择完成方法均失败');
                this.showToast('无法完成选择，请重试。错误信息已记录在控制台。', 'error');
            }
        } catch (error) {
            console.error('MediaSelector (JS): 选择过程发生错误', error);
            this.showToast('选择过程发生错误: ' + error.message, 'error');
        }
    }

    cancelSelection() {
        if (this.options.onCancel && typeof this.options.onCancel === 'function') {
            this.options.onCancel();
        }

        // 如果是在iframe中，向父窗口发送消息
        if (window.parent !== window) {
            window.parent.postMessage({
                type: 'mediaCanceled'
            }, '*');
        }
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

        // 过滤允许的文件类型
        const allowedFiles = Array.from(files).filter(file => this.isFileTypeAllowed(file.type));
        
        if (allowedFiles.length === 0) {
            this.showToast('没有支持的文件类型', 'warning');
            return;
        }

        if (allowedFiles.length !== files.length) {
            this.showToast(`已过滤 ${files.length - allowedFiles.length} 个不支持的文件`, 'warning');
        }

        const fileList = document.getElementById('file-list');
        fileList.classList.remove('hidden');
        
        let html = '';
        allowedFiles.forEach((file, index) => {
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

        // 更新文件输入框
        const dataTransfer = new DataTransfer();
        allowedFiles.forEach(file => dataTransfer.items.add(file));
        document.getElementById('file-input').files = dataTransfer.files;
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
        this.showLoading(true);
    }

    hideUploadProgress() {
        this.showLoading(false);
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

// 全局函数，用于在其他页面中打开媒体选择器
window.openMediaSelector = function(options = {}) {
    const defaultOptions = {
        multiple: false,
        allowedTypes: [],
        maxSelection: 1,
        onSelect: null,
        onCancel: null
    };

    const finalOptions = { ...defaultOptions, ...options };

    // 创建模态框
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
    modal.innerHTML = `
        <div class="bg-white rounded-lg shadow-xl w-full max-w-6xl h-5/6 flex flex-col">
            <div class="flex items-center justify-between p-4 border-b">
                <h2 class="text-lg font-semibold text-gray-900">选择媒体文件</h2>
                <button class="text-gray-400 hover:text-gray-600 transition-colors" onclick="this.closest('.fixed').remove()">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <iframe src="/app/admin/media/selector" class="flex-1 border-0"></iframe>
        </div>
    `;

    document.body.appendChild(modal);

    // 监听iframe消息
    const messageHandler = (event) => {
        if (event.data.type === 'mediaSelected') {
            if (finalOptions.onSelect) {
                finalOptions.onSelect(event.data.data);
            }
            modal.remove();
            window.removeEventListener('message', messageHandler);
        } else if (event.data.type === 'mediaCanceled') {
            if (finalOptions.onCancel) {
                finalOptions.onCancel();
            }
            modal.remove();
            window.removeEventListener('message', messageHandler);
        }
    };

    window.addEventListener('message', messageHandler);

    // 将选项传递给iframe（通过URL参数或其他方式）
    const iframe = modal.querySelector('iframe');
    iframe.onload = function() {
        iframe.contentWindow.postMessage({
            type: 'setOptions',
            options: finalOptions
        }, '*');
    };
};

// 初始化媒体选择器（仅在选择器页面）
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('media-selector-container')) {
        // 监听来自父窗口的选项设置
        window.addEventListener('message', (event) => {
            if (event.data.type === 'setOptions') {
                window.mediaSelector = new MediaSelector(event.data.options);
            }
        });

        // 如果没有收到选项，使用默认选项
        setTimeout(() => {
            if (!window.mediaSelector) {
                window.mediaSelector = new MediaSelector();
            }
        }, 100);
    }
});