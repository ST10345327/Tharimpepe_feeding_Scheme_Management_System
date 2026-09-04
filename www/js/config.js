const FSMS_API_BASE = (() => {
    const origin = window.location.origin || '';
    const pathname = window.location.pathname || '/';
    const emulatorHost = '10.0.2.2:8080';
    const lanHost = '192.168.18.73:8080';
    const userAgent = navigator.userAgent || '';
    const isAndroid = /Android/i.test(userAgent);
    const isFileLikeOrigin = !origin || origin === 'null' || origin.startsWith('file') || origin.startsWith('capacitor');
    const isNativeCapacitor = Boolean(window.Capacitor?.isNativePlatform?.());
    const isAndroidWebView = isAndroid && (isNativeCapacitor || origin === 'http://localhost' || origin === 'https://localhost' || isFileLikeOrigin);

    if (!isAndroidWebView && !isFileLikeOrigin) {
        const projectRoot = pathname
            .split('?')[0]
            .split('#')[0]
            .replace(/\/+(?:index\.(?:html|php))?$/, '')
            .replace(/\/(?:public|www)$/, '')
            .replace(/\/+$/, '');

        return origin.replace(/\/+$/, '') + (projectRoot && projectRoot !== '/' ? projectRoot : '') + '/api';
    }

    // Android app running inside the emulator or on a real device.
    // Use 10.0.2.2 for emulator and the LAN IP for physical device.
    const isProbablyEmulator = origin.includes('10.0.2.2') || /emulator|sdk_gphone/i.test(userAgent);

    const host = isProbablyEmulator ? emulatorHost : lanHost;
    return `http://${host}/api`;
})();
