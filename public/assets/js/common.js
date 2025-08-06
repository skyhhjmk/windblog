// 监控新添加的图片
const observer = new MutationObserver(mutations => {
    mutations.forEach(mutation => {
        mutation.addedNodes.forEach(node => {
            if (node.tagName === 'IMG') {
                node.addEventListener('error', handleImageError);
            }
        });
    });
});

observer.observe(document.body, {
    childList: true,
    subtree: true
});

function handleImageError(event) {
    console.log('检测到图片加载失败:', event.target.src);
    // 设置默认图片
    event.target.src = 'assets/system-image/default-image.png';
}
