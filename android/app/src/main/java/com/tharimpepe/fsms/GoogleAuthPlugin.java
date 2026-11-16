package com.tharimpepe.fsms;

import android.app.Activity;
import android.content.Context;
import android.content.SharedPreferences;
import android.security.KeyPairGeneratorSpec;
import android.util.Base64;
import androidx.credentials.Credential;
import androidx.credentials.CredentialManager;
import androidx.credentials.CustomCredential;
import androidx.credentials.GetCredentialRequest;
import androidx.credentials.GetCredentialResponse;
import androidx.credentials.exceptions.GetCredentialException;
import androidx.core.content.ContextCompat;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.google.android.libraries.identity.googleid.GetSignInWithGoogleOption;
import com.google.android.libraries.identity.googleid.GoogleIdTokenCredential;
import java.math.BigInteger;
import java.nio.charset.StandardCharsets;
import java.security.KeyPairGenerator;
import java.security.KeyStore;
import java.security.PrivateKey;
import java.security.cert.Certificate;
import java.util.Calendar;
import java.util.concurrent.Executor;
import javax.crypto.Cipher;
import javax.security.auth.x500.X500Principal;

@CapacitorPlugin(name = "GoogleAuth")
public class GoogleAuthPlugin extends Plugin {
    private static final String PREFERENCES = "fsms_secure_auth";
    private static final String TOKEN_KEY = "api_token";
    private static final String KEY_ALIAS = "com.tharimpepe.fsms.api-token";

    @PluginMethod
    public void signIn(PluginCall call) {
        String clientId = call.getString("serverClientId");
        String nonce = call.getString("nonce");
        if (clientId == null || clientId.trim().isEmpty() ||
                nonce == null || !nonce.matches("[a-f0-9]{64}")) {
            call.reject("Google Sign-In configuration is missing.");
            return;
        }

        Activity activity = getActivity();
        if (activity == null) {
            call.reject("Google Sign-In is unavailable right now.");
            return;
        }

        GetSignInWithGoogleOption googleOption = new GetSignInWithGoogleOption.Builder(clientId)
                .setNonce(nonce)
                .build();
        GetCredentialRequest request = new GetCredentialRequest.Builder()
                .addCredentialOption(googleOption)
                .build();
        CredentialManager credentialManager = CredentialManager.create(getContext());
        Executor executor = ContextCompat.getMainExecutor(getContext());

        credentialManager.getCredentialAsync(
                activity,
                request,
                null,
                executor,
                new androidx.credentials.CredentialManagerCallback<GetCredentialResponse, GetCredentialException>() {
                    @Override
                    public void onResult(GetCredentialResponse response) {
                        try {
                            Credential credential = response.getCredential();
                            if (!(credential instanceof CustomCredential) ||
                                    !GoogleIdTokenCredential.TYPE_GOOGLE_ID_TOKEN_CREDENTIAL.equals(
                                            ((CustomCredential) credential).getType())) {
                                call.reject("Google did not return a supported sign-in credential.");
                                return;
                            }
                            GoogleIdTokenCredential googleCredential =
                                    GoogleIdTokenCredential.createFrom(((CustomCredential) credential).getData());
                            JSObject result = new JSObject();
                            result.put("idToken", googleCredential.getIdToken());
                            call.resolve(result);
                        } catch (Exception e) {
                            call.reject("Google Sign-In could not be completed.");
                        }
                    }

                    @Override
                    public void onError(GetCredentialException e) {
                        call.reject("Google Sign-In was cancelled or is unavailable.");
                    }
                }
        );
    }

    @PluginMethod
    public void getToken(PluginCall call) {
        try {
            String encrypted = preferences().getString(TOKEN_KEY, null);
            JSObject result = new JSObject();
            result.put("token", encrypted == null ? "" : decrypt(encrypted));
            call.resolve(result);
        } catch (Exception e) {
            call.reject("The saved FSMS session could not be read securely.");
        }
    }

    @PluginMethod
    public void setToken(PluginCall call) {
        String token = call.getString("token");
        if (token == null || token.isEmpty() || token.length() > 4096) {
            call.reject("Invalid FSMS session token.");
            return;
        }
        try {
            preferences().edit().putString(TOKEN_KEY, encrypt(token)).apply();
            call.resolve();
        } catch (Exception e) {
            call.reject("The FSMS session could not be stored securely.");
        }
    }

    @PluginMethod
    public void clearToken(PluginCall call) {
        preferences().edit().remove(TOKEN_KEY).apply();
        call.resolve();
    }

    private SharedPreferences preferences() {
        return getContext().getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE);
    }

    private String encrypt(String value) throws Exception {
        KeyStore keyStore = loadKeyStore();
        if (!keyStore.containsAlias(KEY_ALIAS)) {
            Calendar start = Calendar.getInstance();
            Calendar end = Calendar.getInstance();
            end.add(Calendar.YEAR, 25);
            KeyPairGenerator generator = KeyPairGenerator.getInstance("RSA", "AndroidKeyStore");
            generator.initialize(new KeyPairGeneratorSpec.Builder(getContext())
                    .setAlias(KEY_ALIAS)
                    .setSubject(new X500Principal("CN=" + KEY_ALIAS))
                    .setSerialNumber(BigInteger.ONE)
                    .setStartDate(start.getTime())
                    .setEndDate(end.getTime())
                    .setKeySize(2048)
                    .build());
            generator.generateKeyPair();
            keyStore = loadKeyStore();
        }

        Certificate certificate = keyStore.getCertificate(KEY_ALIAS);
        Cipher cipher = Cipher.getInstance("RSA/ECB/OAEPWithSHA-1AndMGF1Padding");
        cipher.init(Cipher.ENCRYPT_MODE, certificate.getPublicKey());
        byte[] ciphertext = cipher.doFinal(value.getBytes(StandardCharsets.UTF_8));
        return Base64.encodeToString(ciphertext, Base64.NO_WRAP);
    }

    private String decrypt(String encrypted) throws Exception {
        KeyStore keyStore = loadKeyStore();
        if (!keyStore.containsAlias(KEY_ALIAS)) {
            throw new IllegalStateException("Secure storage key is unavailable.");
        }
        PrivateKey privateKey = (PrivateKey) keyStore.getKey(KEY_ALIAS, null);
        Cipher cipher = Cipher.getInstance("RSA/ECB/OAEPWithSHA-1AndMGF1Padding");
        cipher.init(Cipher.DECRYPT_MODE, privateKey);
        byte[] plaintext = cipher.doFinal(Base64.decode(encrypted, Base64.NO_WRAP));
        return new String(plaintext, StandardCharsets.UTF_8);
    }

    private KeyStore loadKeyStore() throws Exception {
        KeyStore keyStore = KeyStore.getInstance("AndroidKeyStore");
        keyStore.load(null);
        return keyStore;
    }
}
