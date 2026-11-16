(() => {
  const nativePlatform = Boolean(window.Capacitor?.isNativePlatform?.());
  let nativeAuthPlugin;

  function plugin() {
    if (!nativePlatform) {
      throw new Error('Native Google Sign-In is unavailable in this app build.');
    }
    if (!nativeAuthPlugin) {
      nativeAuthPlugin = window.Capacitor?.Plugins?.GoogleAuth ||
        window.Capacitor?.registerPlugin?.('GoogleAuth');
    }
    if (!nativeAuthPlugin) {
      throw new Error('Native Google Sign-In is unavailable in this app build.');
    }
    return nativeAuthPlugin;
  }

  async function getChallenge(apiBase, mode, apiToken, csrfToken) {
    if (!/^https:\/\//i.test(apiBase)) {
      throw new Error('Configure an HTTPS API URL in the ignored www/js/config.local.js file.');
    }
    const headers = { 'Content-Type': 'application/json' };
    let url = apiBase.replace(/\/+$/, '') + '/auth/google/challenge';
    let body = '{}';
    if (mode === 'link') {
      if (!apiToken) throw new Error('Sign in before linking a Google account.');
      headers.Authorization = 'Bearer ' + apiToken;
      url = apiBase.replace(/\/+$/, '') + '/auth/google/link-challenge';
    }

    const response = await fetch(url, { method: 'POST', headers, body });
    const result = await response.json();
    if (!response.ok || !result.success || !result.data?.client_id || !result.data?.nonce) {
      throw new Error(result.message || 'Google Sign-In is not configured.');
    }
    return result.data;
  }

  async function submitCredential(apiBase, idToken, nonce, mode, apiToken) {
    const headers = { 'Content-Type': 'application/json' };
    if (mode === 'link') {
      if (!apiToken) throw new Error('Sign in before linking a Google account.');
      headers.Authorization = 'Bearer ' + apiToken;
    }

    const response = await fetch(apiBase.replace(/\/+$/, '') + '/auth/google' + (mode === 'link' ? '/link' : ''), {
      method: 'POST',
      headers,
      body: JSON.stringify({ id_token: idToken, nonce })
    });
    const result = await response.json();
    if (!response.ok || !result.success) {
      throw new Error(result.message || 'Google authentication was rejected.');
    }
    return result;
  }

  async function authenticateNative(apiBase, mode, apiToken) {
    const challenge = await getChallenge(apiBase, mode, apiToken);
    const credential = await plugin().signIn({
      serverClientId: challenge.client_id,
      nonce: challenge.nonce
    });
    return submitCredential(apiBase, credential.idToken, challenge.nonce, mode, apiToken);
  }

  async function renderWebButton(apiBase, element, mode, apiToken, onSuccess, onError) {
    if (!element || nativePlatform) return;
    try {
      const challenge = await getChallenge(apiBase, mode, apiToken);
      await new Promise((resolve, reject) => {
        if (window.google?.accounts?.id) {
          resolve();
          return;
        }
        const script = document.createElement('script');
        script.src = 'https://accounts.google.com/gsi/client';
        script.async = true;
        script.defer = true;
        script.onload = resolve;
        script.onerror = () => reject(new Error('Google Sign-In could not be loaded.'));
        document.head.appendChild(script);
      });

      element.replaceChildren();
      window.google.accounts.id.initialize({
        client_id: challenge.client_id,
        nonce: challenge.nonce,
        callback: async (credential) => {
          try {
            const result = await submitCredential(
              apiBase,
              credential.credential,
              challenge.nonce,
              mode,
              apiToken
            );
            await onSuccess(result);
          } catch (error) {
            onError(error);
          }
        }
      });
      window.google.accounts.id.renderButton(element, {
        type: 'standard',
        theme: 'outline',
        size: 'large',
        text: 'continue_with',
        shape: 'rectangular'
      });
    } catch (error) {
      onError(error);
    }
  }

  window.FsmsGoogleAuth = {
    isNative: nativePlatform,
    authenticateNative,
    renderWebButton,
    getToken: () => plugin().getToken(),
    setToken: (token) => plugin().setToken({ token }),
    clearToken: () => nativePlatform ? plugin().clearToken() : Promise.resolve()
  };
})();
