const FSMS_API_BASE = (() => {
    const origin = window.location.origin;
    const physicalHost = '192.168.18.73:8080';
    // Android emulator: loaded via server.url from PHP dev server
    if (origin.includes('10.0.2.2') || origin.includes('192.168') || origin.includes('172.')) {
        return origin.replace(/\/+$/, '') + '/api';
    }
    // Physical Android device: loaded from local assets, API on host
    if (origin.startsWith('file') || origin.startsWith('capacitor')) {
        return `http://${physicalHost}/api`;
    }
    // Browser dev: same origin as the page
    return origin.replace(/\/+$/, '') + '/api';
})();
