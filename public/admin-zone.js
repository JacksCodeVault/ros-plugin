(function () {
    'use strict';

    var script = document.currentScript;
    if (!script || !script.src) return;

    var iconUrl = new URL('panel/MT_Symbol_Black.png', script.src).href;
    var marker = 'data-ros-plugin-menu-icon';

    function nearestBackground(element) {
        var current = element;
        while (current && current !== document.documentElement) {
            var color = window.getComputedStyle(current).backgroundColor;
            var match = color && color.match(/^rgba?\((\d+),\s*(\d+),\s*(\d+)/);
            if (match && color !== 'rgba(0, 0, 0, 0)') {
                return [Number(match[1]), Number(match[2]), Number(match[3])];
            }
            current = current.parentElement;
        }
        return [255, 255, 255];
    }

    function isDark(element) {
        var rgb = nearestBackground(element);
        return (rgb[0] * 0.299 + rgb[1] * 0.587 + rgb[2] * 0.114) < 128;
    }

    function installIcon(target) {
        if (!target || target.hasAttribute(marker)) return;

        var existing = target.querySelector('img[' + marker + ']');
        if (existing) {
            target.setAttribute(marker, 'true');
            return;
        }

        var icon = document.createElement('img');
        icon.src = iconUrl;
        icon.alt = '';
        icon.setAttribute('aria-hidden', 'true');
        icon.setAttribute(marker, 'true');
        icon.style.width = '20px';
        icon.style.height = '20px';
        icon.style.flex = '0 0 20px';
        icon.style.objectFit = 'contain';
        icon.style.margin = '0 10px 0 0';
        icon.style.filter = isDark(target) ? 'invert(1)' : 'none';

        var nativeIcon = target.querySelector('svg');
        if (nativeIcon) nativeIcon.replaceWith(icon);
        else target.insertBefore(icon, target.firstChild);

        target.setAttribute(marker, 'true');
    }

    function normalizedMenuLabel(target) {
        return [
            target.textContent || '',
            target.getAttribute('aria-label') || '',
            target.getAttribute('title') || ''
        ].join(' ').replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function isLeftRailTarget(target) {
        if (!target || !target.getBoundingClientRect) return false;
        var rect = target.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0 && rect.left < 72 && rect.top >= 40;
    }

    function isRosPluginTarget(target) {
        var label = normalizedMenuLabel(target);
        var href = target.getAttribute('href') || '';
        return label.indexOf('ros plugin') !== -1 ||
            (href.indexOf('ros-plugin') !== -1 &&
                (href.indexOf('page=panel') !== -1 || href.indexOf('/public.php') !== -1));
    }

    function removeIconsOutsideLeftRail() {
        document.querySelectorAll('img[' + marker + ']').forEach(function (icon) {
            if (!isLeftRailTarget(icon.parentElement)) icon.remove();
        });
        document.querySelectorAll('[' + marker + ']:not(img)').forEach(function (target) {
            if (!isLeftRailTarget(target)) target.removeAttribute(marker);
        });
    }

    function applyMenuIcon() {
        removeIconsOutsideLeftRail();

        document.querySelectorAll('a, button, [role="menuitem"]').forEach(function (item) {
            if (isLeftRailTarget(item) && isRosPluginTarget(item)) installIcon(item);
        });
    }

    applyMenuIcon();
    new MutationObserver(applyMenuIcon).observe(document.body, { childList: true, subtree: true });
}());
