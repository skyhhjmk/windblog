/**
 * 前端时间本地化工具
 *
 * 将UTC时间字符串自动转换为用户本地时间
 */

(function (window) {
    'use strict';

    /**
     * 将UTC时间字符串转换为本地时间字符串
     *
     * @param {string} utcDateStr UTC时间字符串（如 '2025-10-26 16:23:11'）
     * @param {string} format 格式模板（'YYYY-MM-DD HH:mm:ss' 或 'YYYY-MM-DD' 等）
     * @returns {string} 本地时间字符串
     */
    function formatUTCToLocal(utcDateStr, format) {
        if (!utcDateStr) return '-';

        format = format || 'YYYY-MM-DD HH:mm:ss';

        try {
            // 将UTC时间字符串转换为Date对象
            // 如果字符串没有时区信息，添加UTC标记
            let dateStr = utcDateStr.trim();
            if (!/Z|[\+\-]\d{2}:\d{2}$/.test(dateStr)) {
                dateStr += ' UTC';
            }

            const date = new Date(dateStr);

            // 检查日期是否有效
            if (isNaN(date.getTime())) {
                console.warn('Invalid date string:', utcDateStr);
                return utcDateStr;
            }

            // 格式化为本地时间
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            const hours = String(date.getHours()).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');
            const seconds = String(date.getSeconds()).padStart(2, '0');

            // 根据格式模板替换
            let result = format;
            result = result.replace('YYYY', year);
            result = result.replace('MM', month);
            result = result.replace('DD', day);
            result = result.replace('HH', hours);
            result = result.replace('mm', minutes);
            result = result.replace('ss', seconds);

            return result;
        } catch (e) {
            console.error('Error formatting date:', e, utcDateStr);
            return utcDateStr;
        }
    }

    /**
     * 人性化时间显示（几分钟前、几小时前等）
     *
     * @param {string} utcDateStr UTC时间字符串
     * @returns {string} 人性化时间字符串
     */
    function humanTime(utcDateStr) {
        if (!utcDateStr) return '-';

        try {
            let dateStr = utcDateStr.trim();
            if (!/Z|[\+\-]\d{2}:\d{2}$/.test(dateStr)) {
                dateStr += ' UTC';
            }

            const date = new Date(dateStr);
            if (isNaN(date.getTime())) {
                return utcDateStr;
            }

            const now = new Date();
            const diff = Math.floor((now - date) / 1000); // 秒

            if (diff < 0) {
                return formatUTCToLocal(utcDateStr, 'YYYY-MM-DD');
            }

            if (diff < 60) {
                return diff + '秒前';
            } else if (diff < 3600) {
                return Math.floor(diff / 60) + '分钟前';
            } else if (diff < 86400) {
                return Math.floor(diff / 3600) + '小时前';
            } else if (diff < 2592000) {
                return Math.floor(diff / 86400) + '天前';
            } else {
                return formatUTCToLocal(utcDateStr, 'YYYY-MM-DD');
            }
        } catch (e) {
            console.error('Error calculating human time:', e, utcDateStr);
            return utcDateStr;
        }
    }

    /**
     * 将UTC时间戳（秒）转换为本地时间字符串
     *
     * @param {number} timestamp UTC时间戳（秒）
     * @param {string} format 格式模板
     * @returns {string} 本地时间字符串
     */
    function formatTimestamp(timestamp, format) {
        if (!timestamp) return '-';

        format = format || 'YYYY-MM-DD HH:mm:ss';

        try {
            const date = new Date(timestamp * 1000);

            if (isNaN(date.getTime())) {
                return '-';
            }

            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            const hours = String(date.getHours()).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');
            const seconds = String(date.getSeconds()).padStart(2, '0');

            let result = format;
            result = result.replace('YYYY', year);
            result = result.replace('MM', month);
            result = result.replace('DD', day);
            result = result.replace('HH', hours);
            result = result.replace('mm', minutes);
            result = result.replace('ss', seconds);

            return result;
        } catch (e) {
            console.error('Error formatting timestamp:', e, timestamp);
            return '-';
        }
    }

    /**
     * 自动转换页面中所有标记为UTC时间的元素
     *
     * 使用方法：在HTML元素上添加 data-utc-time 属性
     * <span data-utc-time="2025-10-26 16:23:11" data-format="YYYY-MM-DD HH:mm:ss"></span>
     */
    function autoConvertUTCElements() {
        const elements = document.querySelectorAll('[data-utc-time]');

        elements.forEach(function (el) {
            const utcTime = el.getAttribute('data-utc-time');
            const format = el.getAttribute('data-format') || 'YYYY-MM-DD HH:mm:ss';
            const useHuman = el.hasAttribute('data-human');

            if (utcTime) {
                const localTime = useHuman ? humanTime(utcTime) : formatUTCToLocal(utcTime, format);
                el.textContent = localTime;
            }
        });
    }

    // 导出到全局对象
    window.DateTimeUtils = {
        formatUTCToLocal: formatUTCToLocal,
        humanTime: humanTime,
        formatTimestamp: formatTimestamp,
        autoConvertUTCElements: autoConvertUTCElements
    };

    // DOM加载完成后自动转换
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoConvertUTCElements);
    } else {
        autoConvertUTCElements();
    }

    // 支持PJAX等动态加载
    if (typeof document.addEventListener === 'function') {
        document.addEventListener('pjax:complete', autoConvertUTCElements);
        document.addEventListener('page:ready', autoConvertUTCElements);
    }

})(window);
