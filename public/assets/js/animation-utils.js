/**
 * 风屿雨博客 - 动画工具库
 * 提供增强的动画效果和交互功能
 */

/**
 * 滚动触发动画管理器
 */
class ScrollAnimationManager {
    constructor() {
        this.animatedElements = [];
        this.isActive = true;
        this.throttleTimeout = null;
        this.throttleDelay = 100;
        this.init();
    }

    /**
     * 初始化滚动触发动画
     */
    init() {
        // 收集所有需要滚动触发动画的元素
        this.animatedElements = document.querySelectorAll('.fade-in-on-scroll');
        
        // 检查用户是否偏好减少动画
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            this.isActive = false;
            this.animatedElements.forEach(el => {
                el.classList.add('visible');
            });
            return;
        }

        // 初始检查可见性
        this.checkVisibility();
        
        // 绑定滚动事件（使用节流函数优化性能）
        window.addEventListener('scroll', this.throttle(() => {
            if (this.isActive) {
                this.checkVisibility();
            }
        }, this.throttleDelay));
        
        // 绑定窗口调整事件
        window.addEventListener('resize', this.throttle(() => {
            if (this.isActive) {
                this.checkVisibility();
            }
        }, this.throttleDelay));
        
        // 绑定页面加载完成事件
        window.addEventListener('load', () => {
            if (this.isActive) {
                this.checkVisibility();
            }
        });
        
        // 绑定PJAX页面切换完成事件
        document.addEventListener('page:ready', () => {
            this.animatedElements = document.querySelectorAll('.fade-in-on-scroll');
            if (this.isActive) {
                this.checkVisibility();
            }
        });
    }

    /**
     * 检查元素可见性并触发动画
     */
    checkVisibility() {
        this.animatedElements.forEach(el => {
            // 跳过已经可见的元素
            if (el.classList.contains('visible')) {
                return;
            }
            
            const elementTop = el.getBoundingClientRect().top;
            const elementBottom = el.getBoundingClientRect().bottom;
            const isVisible = (
                elementTop < window.innerHeight - 100 &&
                elementBottom > 0
            );
            
            if (isVisible) {
                // 获取动画延迟
                const animationDelay = el.dataset.animationDelay || 0;
                setTimeout(() => {
                    el.classList.add('visible');
                }, parseInt(animationDelay) * 100);
            }
        });
    }

    /**
     * 节流函数，限制函数执行频率
     * @param {Function} func - 要节流的函数
     * @param {number} delay - 延迟时间（毫秒）
     * @returns {Function} 节流后的函数
     */
    throttle(func, delay) {
        return (...args) => {
            if (!this.throttleTimeout) {
                this.throttleTimeout = setTimeout(() => {
                    func.apply(this, args);
                    this.throttleTimeout = null;
                }, delay);
            }
        };
    }

    /**
     * 暂停动画
     */
    pause() {
        this.isActive = false;
    }

    /**
     * 恢复动画
     */
    resume() {
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            this.isActive = true;
            this.checkVisibility();
        }
    }
    
    /**
     * PJAX页面切换时重新初始化动画管理器
     */
    reinit() {
        // 移除旧的事件监听器（使用命名函数以便正确移除）
        const scrollHandler = this.throttle(() => {
            if (this.isActive) {
                this.checkVisibility();
            }
        }, this.throttleDelay);
        
        const resizeHandler = this.throttle(() => {
            if (this.isActive) {
                this.checkVisibility();
            }
        }, this.throttleDelay);
        
        window.removeEventListener('scroll', scrollHandler);
        window.removeEventListener('resize', resizeHandler);
        
        // 重置属性
        this.animatedElements = [];
        this.throttleTimeout = null;
        
        // 重新初始化
        this.init();
    }
}

/**
 * 动画工具库
 */
const AnimationUtils = {
    // 滚动动画管理器实例
    scrollManager: null,
    
    /**
     * 初始化所有动画功能
     */
    init() {
        // 初始化滚动动画管理器
        this.scrollManager = new ScrollAnimationManager();
        
        // 增强按钮交互
        this.enhanceButtonInteractions();
        
        // 增强卡片交互
        this.enhanceCardInteractions();
        
        // 优化导航动画
        this.optimizeNavigationAnimation();
        
        // 添加打字机效果支持
        this.initTypewriterEffect();
        
        // 增强触摸设备交互
        this.enhanceTouchInteractions();
        
        // 初始化PJAX动画集成
        this.initPJAXAnimations();
    },
    
    /**
     * 初始化PJAX动画集成
     */
    initPJAXAnimations() {
        // 监听PJAX内容替换前事件
        document.addEventListener('pjax:beforeReplace', (e, contents) => {
            // 为新内容中的区块添加动画类和延迟
            const sections = contents.querySelectorAll('section, .main-section, .article-section');
            sections.forEach((section, index) => {
                // 仅为没有动画类的区块添加
                if (!section.classList.contains('fade-in-on-scroll') && 
                    !section.classList.contains('no-animation')) {
                    section.classList.add('fade-in-on-scroll');
                    
                    // 添加动画延迟，创建层次感
                    if (!section.dataset.animationDelay) {
                        section.dataset.animationDelay = Math.min(index * 0.1, 0.5);
                    }
                }
            });
            
            // 确保新内容中的交互元素也有动画效果
            const interactiveElements = contents.querySelectorAll('button, a:not([href^="#"]), .card');
            interactiveElements.forEach(element => {
                if (!element.classList.contains('no-animation')) {
                    // 添加硬件加速类提升性能
                    if (!element.classList.contains('accelerated')) {
                        element.classList.add('accelerated');
                    }
                }
            });
        });
        
        // 监听PJAX完成事件
        document.addEventListener('pjax:end', () => {
            // 重新初始化动画系统
            this.reinit();
        });
        
        // 监听PJAX错误事件
        document.addEventListener('pjax:error', (e, xhr) => {
            // 显示错误通知
            if (xhr.status === 0) {
                this.showNotification('网络连接错误，请检查网络设置', 'error');
            } else if (xhr.status >= 500) {
                this.showNotification('服务器错误，请稍后再试', 'error');
            }
            
            // 重置动画状态
            if (this.scrollManager) {
                this.scrollManager.resume();
            }
        });
        
        // 监听页面切换动画开始事件
        document.addEventListener('pjax:start', () => {
            // 暂停滚动动画以提高性能
            if (this.scrollManager) {
                this.scrollManager.pause();
            }
        });
    },
    
    /**
     * 重新初始化所有动画功能（用于PJAX页面切换后）
     */
    reinit() {
        // 重新初始化滚动动画管理器
        if (this.scrollManager) {
            this.scrollManager.reinit();
        } else {
            this.scrollManager = new ScrollAnimationManager();
        }
        
        // 重新初始化其他动画功能
        this.enhanceButtonInteractions();
        this.enhanceCardInteractions();
        this.optimizeNavigationAnimation();
        this.initTypewriterEffect();
        this.enhanceTouchInteractions();
        
        // 触发自定义事件，通知动画系统已准备就绪
        document.dispatchEvent(new CustomEvent('animations:ready'));
    },
    
    /**
     * 增强按钮交互效果
     */
    enhanceButtonInteractions() {
        const buttons = document.querySelectorAll('button, a');
        buttons.forEach(button => {
            // 为没有特定交互类的按钮添加触摸反馈
            if (!button.classList.contains('touch-feedback')) {
                button.classList.add('touch-feedback');
            }
            
            // 为主要按钮添加涟漪效果
            if (button.classList.contains('btn-primary') || button.classList.contains('bg-blue-600')) {
                if (!button.classList.contains('ripple-btn')) {
                    button.classList.add('ripple-btn');
                }
            }
        });
    },
    
    /**
     * 增强卡片交互效果
     */
    enhanceCardInteractions() {
        const cards = document.querySelectorAll('.bg-white.rounded-xl, .bg-white.rounded-2xl');
        cards.forEach((card, index) => {
            // 检查是否已经有任何悬停效果类
            const hasHoverEffect = card.classList.contains('hover-lift') || 
                                  card.classList.contains('hover-scale') || 
                                  card.classList.contains('hover-spin') || 
                                  card.classList.contains('hover-rotate') || 
                                  card.classList.contains('hover-animate');
            
            // 只有在没有任何悬停效果类的情况下才添加默认的悬停提升效果
            if (!hasHoverEffect) {
                card.classList.add('hover-lift', 'accelerated');
            } else if (!card.classList.contains('accelerated')) {
                // 如果已经有其他悬停效果，但没有硬件加速类，则添加硬件加速
                card.classList.add('accelerated');
            }
            
            // 为卡片添加交错动画延迟
            const delay = (index % 3) * 0.1;
            card.style.animationDelay = `${delay}s`;
        });
    },
    
    /**
     * 优化导航动画
     */
    optimizeNavigationAnimation() {
        // 增强PJAX进度条动画
        const progressBar = document.getElementById('pjax-progress');
        if (progressBar) {
            // 设置进度条基础样式
            progressBar.style.transition = 'width 0.3s ease-out, opacity 0.3s ease-out';
            progressBar.style.backgroundImage = 'linear-gradient(to right, #6366f1, #8b5cf6)';
        }
        
        // 添加导航栏滚动效果
        const header = document.querySelector('header');
        if (header) {
            let lastScrollTop = 0;
            
            window.addEventListener('scroll', this.throttle(() => {
                const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
                
                if (scrollTop > lastScrollTop && scrollTop > 100) {
                    // 向下滚动且已滚动一定距离，隐藏导航栏
                    header.classList.add('nav-hidden');
                } else if (scrollTop < lastScrollTop - 20) {
                    // 向上滚动，显示导航栏
                    header.classList.remove('nav-hidden');
                }
                
                lastScrollTop = scrollTop <= 0 ? 0 : scrollTop; // 避免负值
            }, 150));
        }
    },
    
    /**
     * 节流函数（工具类内部使用）
     */
    throttle(func, delay) {
        let timeoutId;
        return function(...args) {
            if (!timeoutId) {
                timeoutId = setTimeout(() => {
                    func.apply(this, args);
                    timeoutId = null;
                }, delay);
            }
        };
    },
    
    /**
     * 初始化打字机效果
     */
    initTypewriterEffect() {
        const typewriters = document.querySelectorAll('.typewriter');
        typewriters.forEach(element => {
            // 获取原始文本内容
            const originalText = element.textContent;
            
            // 设置初始宽度为0
            element.style.width = '0';
            
            // 绑定动画完成事件
            element.addEventListener('animationend', function(e) {
                if (e.animationName === 'typing') {
                    // 打字动画完成后移除光标
                    element.style.borderRight = 'none';
                }
            });
        });
    },
    
    /**
     * 增强触摸设备交互
     */
    enhanceTouchInteractions() {
        if ('ontouchstart' in document.documentElement) {
            // 为触摸设备添加特殊处理
            document.body.classList.add('touch-device');
            
            // 为交互元素添加触摸事件处理
            const interactiveElements = document.querySelectorAll('.hover-lift, .hover-animate');
            interactiveElements.forEach(element => {
                // 添加触摸反馈
                element.addEventListener('touchstart', function() {
                    this.style.transform = 'translateY(-2px)';
                }, { passive: true });
                
                element.addEventListener('touchend', function() {
                    this.style.transform = 'translateY(0)';
                }, { passive: true });
                
                element.addEventListener('touchcancel', function() {
                    this.style.transform = 'translateY(0)';
                }, { passive: true });
            });
        }
    },
    
    /**
     * 为元素添加动画类
     * @param {HTMLElement} element - 目标元素
     * @param {string} animationClass - 动画类名
     */
    animateElement(element, animationClass) {
        // 移除可能已存在的动画类
        const animationClasses = [
            'animate-fade-in', 'animate-fade-in-up', 'animate-fade-in-down',
            'animate-fade-in-left', 'animate-fade-in-right', 'animate-shake',
            'animate-bounce', 'animate-pulse'
        ];
        
        animationClasses.forEach(cls => {
            element.classList.remove(cls);
        });
        
        // 添加新的动画类
        element.classList.add(animationClass);
        
        // 监听动画结束，清理类名以便下次使用
        const handleAnimationEnd = function() {
            element.classList.remove(animationClass);
            element.removeEventListener('animationend', handleAnimationEnd);
        };
        
        element.addEventListener('animationend', handleAnimationEnd);
    },
    
    /**
     * 显示通知消息（使用原生实现）
     * @param {string} message - 消息内容
     * @param {string} type - 消息类型：success, error, info, warning
     * @param {number} duration - 持续时间（毫秒）
     */
    showNotification(message, type = 'info', duration = 3000) {
        // 获取或创建通知容器
        let notificationContainer = document.getElementById('notification-container');
        if (!notificationContainer) {
            notificationContainer = document.createElement('div');
            notificationContainer.id = 'notification-container';
            notificationContainer.className = 'fixed top-4 right-4 z-50 flex flex-col items-end gap-4';
            document.body.appendChild(notificationContainer);
        }
        
        // 创建通知元素
        const notification = document.createElement('div');
        notification.className = `px-4 py-3 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full w-full max-w-sm`;
        
        // 设置不同类型的样式
        const typeStyles = {
            success: 'bg-green-500 text-white',
            error: 'bg-red-500 text-white',
            info: 'bg-blue-500 text-white',
            warning: 'bg-yellow-500 text-white'
        };
        
        // 应用样式
        notification.classList.add(...typeStyles[type]?.split(' ') || typeStyles.info.split(' '));
        notification.textContent = message;
        
        // 添加关闭按钮
        const closeBtn = document.createElement('button');
        closeBtn.className = 'absolute top-2 right-2 text-white opacity-70 hover:opacity-100 transition-opacity';
        closeBtn.textContent = '×';
        closeBtn.style.fontSize = '1.2rem';
        closeBtn.style.lineHeight = '1';
        closeBtn.style.background = 'none';
        closeBtn.style.border = 'none';
        closeBtn.style.padding = '0';
        closeBtn.style.cursor = 'pointer';
        closeBtn.onclick = () => {
            this.removeNotification(notification);
        };
        notification.style.position = 'relative';
        notification.appendChild(closeBtn);
        
        // 添加到容器顶部，使新通知显示在上面
        notificationContainer.insertBefore(notification, notificationContainer.firstChild);
        
        // 触发入场动画
        setTimeout(() => {
            notification.style.transform = 'translateX(0)';
        }, 10);
        
        // 设置自动关闭
        const timer = setTimeout(() => {
            this.removeNotification(notification);
        }, duration);
        
        // 存储计时器引用，以便需要时可以清除
        notification.dataset.timerId = timer;
        
        return notification;
    },
    
    /**
     * 移除通知并调整其他通知位置
     * @param {HTMLElement} notification - 要移除的通知元素
     */
    removeNotification(notification) {
        // 清除自动关闭计时器
        if (notification.dataset.timerId) {
            clearTimeout(parseInt(notification.dataset.timerId));
        }
        
        // 触发退场动画
        notification.style.transform = 'translateX(100%)';
        notification.style.opacity = '0';
        
        // 动画结束后移除元素
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }
};

/**
 * DOM加载完成后初始化动画工具
 */
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        AnimationUtils.init();
    });
} else {
    // 已经加载完成，直接初始化
    AnimationUtils.init();
}

/**
 * 暴露AnimationUtils到全局，便于其他脚本使用
 */
window.AnimationUtils = AnimationUtils;