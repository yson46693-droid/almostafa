/**
 * نظام WebAuthn مبسط ونظيف
 */

class SimpleWebAuthn {
    constructor() {
        this.apiBase = this.getApiBase();
    }

    /**
     * الحصول على المسار الأساسي لـ API
     */
    getApiBase() {
        const currentPath = window.location.pathname;
        const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
        
        // استخدام مسار مطلق بناءً على موقع الصفحة الحالية
        // إذا كنا في الجذر (مثل /v1/profile.php)، المسار سيكون /v1/api/webauthn_register.php
        // إذا كنا في مجلد فرعي، نستخدم المسار النسبي
        
        if (pathParts.length === 0) {
            // في الجذر - استخدام مسار نسبي
            return 'api/webauthn_register.php';
        } else {
            // في مجلد فرعي - بناء مسار مطلق
            const basePath = '/' + pathParts[0];
            return basePath + '/api/webauthn_register.php';
        }
    }

    /**
     * التحقق من دعم WebAuthn
     * محسّن للكشف عن دعم WebAuthn على جميع الأجهزة
     */
    isSupported() {
        // التحقق الأساسي من WebAuthn API
        if (!window.PublicKeyCredential || !navigator.credentials || !navigator.credentials.create) {
            return false;
        }
        
        // التحقق من دعم PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable
        // (مفيد للكشف عن دعم platform authenticators)
        if (typeof PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable === 'function') {
            return true;
        }
        
        // حتى لو لم يكن isUserVerifyingPlatformAuthenticatorAvailable متاحاً،
        // قد يكون WebAuthn مدعوماً (خاصة على Android القديم)
        return true;
    }
    
    /**
     * التحقق من توفر platform authenticator (Face ID, Touch ID, Android biometrics)
     */
    async isPlatformAuthenticatorAvailable() {
        try {
            if (typeof PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable === 'function') {
                return await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
            }
            // إذا لم يكن متاحاً، نفترض أنه متاح (للتوافق مع Android القديم)
            return true;
        } catch (error) {
            console.warn('Error checking platform authenticator availability:', error);
            // في حالة الخطأ، نفترض أنه متاح
            return true;
        }
    }

    /**
     * تحويل Base64 إلى ArrayBuffer
     */
    base64ToArrayBuffer(base64) {
        if (typeof base64 !== 'string' || base64.length === 0) {
            throw new Error('بيانات Base64 غير صالحة');
        }

        const normalized = this.normalizeBase64(base64);
        let binaryString;

        try {
            binaryString = window.atob(normalized);
        } catch (error) {
            console.error('WebAuthn: Invalid Base64 input', {
                original: base64,
                normalized,
                length: normalized.length,
                error: error.message
            });
            throw new Error('فشل في قراءة بيانات الترميز (Base64).');
        }
        const bytes = new Uint8Array(binaryString.length);
        for (let i = 0; i < binaryString.length; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }
        return bytes.buffer;
    }

    /**
     * تحويل ArrayBuffer إلى Base64
     */
    arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary);
    }

    /**
     * تحويل base64url إلى base64 عادي مع الحشو
     */
    normalizeBase64(value) {
        let normalized = value.replace(/-/g, '+').replace(/_/g, '/').replace(/\s+/g, '');
        const paddingNeeded = normalized.length % 4;
        if (paddingNeeded) {
            normalized += '='.repeat(4 - paddingNeeded);
        }
        return normalized;
    }

    /**
     * محاولة تخمين اسم الجهاز من الـ User-Agent
     * محسّن لدعم جميع الأجهزة وأنظمة Android
     */
    detectDeviceName() {
        const ua = navigator.userAgent || '';

        // iOS devices
        if (/iPhone/i.test(ua)) {
            const match = ua.match(/OS\s+([\d_]+)/i);
            return match ? `iPhone (iOS ${match[1].replace(/_/g, '.')})` : 'iPhone';
        }
        if (/iPad/i.test(ua)) {
            const match = ua.match(/OS\s+([\d_]+)/i);
            return match ? `iPad (iOS ${match[1].replace(/_/g, '.')})` : 'iPad';
        }
        
        // Android devices - تحسين الكشف
        if (/Android/i.test(ua)) {
            const androidMatch = ua.match(/Android\s+([\d\.]+)/i);
            let deviceName = 'Android Device';
            
            // الكشف عن نوع الجهاز
            if (/Samsung/i.test(ua)) {
                deviceName = 'Samsung';
            } else if (/OPPO/i.test(ua)) {
                deviceName = 'OPPO';
            } else if (/OnePlus/i.test(ua)) {
                deviceName = 'OnePlus';
            } else if (/Xiaomi/i.test(ua)) {
                deviceName = 'Xiaomi';
            } else if (/Huawei/i.test(ua)) {
                deviceName = 'Huawei';
            } else if (/Realme/i.test(ua)) {
                deviceName = 'Realme';
            } else if (/Vivo/i.test(ua)) {
                deviceName = 'Vivo';
            } else if (/Redmi/i.test(ua)) {
                deviceName = 'Redmi';
            }
            
            if (androidMatch) {
                deviceName += ` (Android ${androidMatch[1]})`;
            }
            
            return deviceName;
        }
        
        // Desktop devices
        if (/Macintosh/i.test(ua)) {
            const match = ua.match(/Mac OS X\s+([\d_]+)/i);
            return match ? `Mac (macOS ${match[1].replace(/_/g, '.')})` : 'Mac';
        }
        if (/Windows/i.test(ua)) {
            if (/Windows NT 10.0/i.test(ua)) {
                return 'Windows 10/11';
            } else if (/Windows NT 6.3/i.test(ua)) {
                return 'Windows 8.1';
            } else if (/Windows NT 6.2/i.test(ua)) {
                return 'Windows 8';
            } else if (/Windows NT 6.1/i.test(ua)) {
                return 'Windows 7';
            }
            return 'Windows';
        }
        if (/Linux/i.test(ua)) {
            return 'Linux';
        }

        // Browsers (fallback)
        if (/Chrome/i.test(ua) && !/Edge|Opera|OPR/i.test(ua)) {
            const match = ua.match(/Chrome\/([\d\.]+)/i);
            return match ? `Chrome ${match[1]}` : 'Chrome Browser';
        }
        if (/Safari/i.test(ua) && !/Chrome/i.test(ua)) {
            const match = ua.match(/Version\/([\d\.]+)/i);
            return match ? `Safari ${match[1]}` : 'Safari Browser';
        }
        if (/Firefox/i.test(ua)) {
            const match = ua.match(/Firefox\/([\d\.]+)/i);
            return match ? `Firefox ${match[1]}` : 'Firefox Browser';
        }
        if (/Edge/i.test(ua)) {
            const match = ua.match(/Edge\/([\d\.]+)/i);
            return match ? `Edge ${match[1]}` : 'Edge Browser';
        }

        return 'Unknown Device';
    }

    /**
     * تسجيل بصمة جديدة
     */
    async register(deviceName = null) {
        try {
            // التحقق من الدعم
            if (!this.isSupported()) {
                throw new Error('WebAuthn غير مدعوم في هذا المتصفح. يرجى استخدام متصفح حديث.');
            }

            // التحقق من HTTPS (مطلوب لـ WebAuthn)
            if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
                throw new Error('WebAuthn يتطلب HTTPS. الموقع الحالي: ' + window.location.protocol);
            }

            // الحصول على اسم الجهاز بشكل تلقائي إن لم يُرسل من الواجهة
            if (!deviceName || deviceName.trim() === '') {
                deviceName = this.detectDeviceName();
            }
            deviceName = deviceName.trim();

            // 1. الحصول على challenge من الخادم
            const challengeResponse = await fetch(this.apiBase, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'challenge'
                })
            });

            if (!challengeResponse.ok) {
                // معالجة خاصة لخطأ 401 (Unauthorized)
                if (challengeResponse.status === 401) {
                    const errorData = await challengeResponse.json().catch(() => ({}));
                    const errorMessage = errorData.message || 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول';
                    throw new Error(errorMessage);
                }
                throw new Error(`خطأ في الاتصال بالخادم: ${challengeResponse.status}`);
            }

            const challengeData = await challengeResponse.json();

            if (!challengeData.success || !challengeData.data) {
                throw new Error(challengeData.message || challengeData.error || 'فشل في إنشاء التحدي');
            }

            const challenge = challengeData.data;

            // 2. تحويل البيانات إلى ArrayBuffer
            const challengeBuffer = this.base64ToArrayBuffer(challenge.challenge);
            const userIdBuffer = this.base64ToArrayBuffer(challenge.user.id);

            // 3. تحويل excludeCredentials
            const excludeCredentials = (challenge.excludeCredentials || [])
                .filter(cred => cred && cred.id)
                .map(cred => {
                    try {
                        return {
                            id: this.base64ToArrayBuffer(cred.id),
                            type: cred.type || 'public-key'
                        };
                    } catch (error) {
                        console.warn('WebAuthn: تجاهل excludeCredential غير صالح', cred, error);
                        return null;
                    }
                })
                .filter(Boolean);

            // 4. إعداد rpId
            let rpId = challenge.rp?.id || window.location.hostname;
            rpId = rpId.replace(/^www\./, '').split(':')[0];

            // 5. إنشاء challenge object - نظام محسّن يعمل على جميع الأجهزة
            const pubKeyCredParams = Array.isArray(challenge.pubKeyCredParams) && challenge.pubKeyCredParams.length > 0
                ? challenge.pubKeyCredParams
                : [
                    { type: 'public-key', alg: -7 },   // ES256 - الأكثر شيوعاً
                    { type: 'public-key', alg: -257 }, // RS256
                    { type: 'public-key', alg: -8 },   // EdDSA (Ed25519) - للأجهزة الحديثة
                    { type: 'public-key', alg: -35 },  // ES384
                    { type: 'public-key', alg: -36 },   // ES512
                    { type: 'public-key', alg: -37 },  // PS256
                    { type: 'public-key', alg: -38 },   // PS384
                    { type: 'public-key', alg: -39 }    // PS512
                ];

            const authenticatorSelection = { ...(challenge.authenticatorSelection || {}) };

            // التأكد من userVerification
            if (!authenticatorSelection.userVerification) {
                authenticatorSelection.userVerification = 'preferred';
            }

            // التأكد من requireResidentKey (مطلوب لحفظ passkeys)
            if (!('requireResidentKey' in authenticatorSelection)) {
                authenticatorSelection.requireResidentKey = true;
            }

            // الكشف عن الأجهزة والمتصفحات
            const ua = navigator.userAgent || '';
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(ua);
            const isAndroid = /Android/i.test(ua);
            const isChrome = /Chrome/i.test(ua) && !/Edge|Opera|OPR/i.test(ua);
            const isSafari = /Safari/i.test(ua) && !/Chrome/i.test(ua);
            const isFirefox = /Firefox/i.test(ua);
            
            // الكشف عن إصدار Android
            let androidVersion = null;
            if (isAndroid) {
                const match = ua.match(/Android\s+([\d\.]+)/i);
                if (match) {
                    androidVersion = parseFloat(match[1]);
                }
            }

            // إعدادات محسّنة لجميع الأجهزة:
            // - لا نحدد authenticatorAttachment - هذا يسمح بجميع الأنواع:
            //   * platform: Face ID, Touch ID, Android biometrics
            //   * cross-platform: USB keys, Bluetooth, Google Passkeys
            //   * null: يسمح بكلاهما (الأفضل للتوافق)
            
            // للأجهزة المحمولة (Android, iOS):
            if (isMobile) {
                // على Android القديم (أقل من 7.0)، قد نحتاج إعدادات خاصة
                if (isAndroid && androidVersion && androidVersion < 7.0) {
                    // Android القديم - نستخدم إعدادات أكثر مرونة
                    // لا نحدد authenticatorAttachment للسماح بجميع الأنواع
                    if ('authenticatorAttachment' in authenticatorSelection) {
                        delete authenticatorSelection.authenticatorAttachment;
                    }
                } else {
                    // Android الحديث أو iOS - نسمح بجميع الأنواع
                    if ('authenticatorAttachment' in authenticatorSelection) {
                        delete authenticatorSelection.authenticatorAttachment;
                    }
                }
            } else {
                // أجهزة سطح المكتب - نسمح بجميع الأنواع (platform + cross-platform)
                if ('authenticatorAttachment' in authenticatorSelection) {
                    delete authenticatorSelection.authenticatorAttachment;
                }
            }

            // دعم Google Passkeys (Chrome على Android/Desktop):
            // Google Passkeys تعمل كـ cross-platform authenticator
            // لذلك لا نحدد authenticatorAttachment للسماح بها
            
            // دعم Safari Passkeys (iOS/macOS):
            // Safari Passkeys تعمل كـ platform authenticator
            // لذلك لا نحدد authenticatorAttachment للسماح بها أيضاً

            const publicKeyTimeout = typeof challenge.timeout === 'number' ? challenge.timeout : 60000;
            const attestation = challenge.attestation || 'none';

            const publicKeyCredentialCreationOptions = {
                challenge: challengeBuffer,
                rp: {
                    name: challenge.rp?.name || 'نظام الإدارة المتكاملة',
                    id: rpId
                },
                user: {
                    id: userIdBuffer,
                    name: challenge.user.name,
                    displayName: challenge.user.displayName || challenge.user.name
                },
                pubKeyCredParams,
                timeout: publicKeyTimeout,
                attestation
            };

            if (Object.keys(authenticatorSelection).length > 0) {
                publicKeyCredentialCreationOptions.authenticatorSelection = authenticatorSelection;
            }

            if (excludeCredentials.length > 0) {
                publicKeyCredentialCreationOptions.excludeCredentials = excludeCredentials;
            }

            console.log('WebAuthn Registration Options:', {
                rpId: rpId,
                timeout: publicKeyCredentialCreationOptions.timeout,
                authenticatorSelection: publicKeyCredentialCreationOptions.authenticatorSelection,
                attestation: publicKeyCredentialCreationOptions.attestation,
                pubKeyCredParams: publicKeyCredentialCreationOptions.pubKeyCredParams,
                excludeCredentialsCount: excludeCredentials.length,
                deviceInfo: {
                    isMobile: isMobile,
                    isAndroid: isAndroid,
                    androidVersion: androidVersion,
                    isChrome: isChrome,
                    isSafari: isSafari,
                    isFirefox: isFirefox,
                    userAgent: ua.substring(0, 100)
                }
            });

            // 6. إنشاء الاعتماد
            let credential;
            try {
                console.log('Requesting WebAuthn credential...', {
                    options: {
                        rpId: publicKeyCredentialCreationOptions.rp.id,
                        timeout: publicKeyCredentialCreationOptions.timeout,
                        authenticatorSelection: publicKeyCredentialCreationOptions.authenticatorSelection,
                        hasExcludeCredentials: !!publicKeyCredentialCreationOptions.excludeCredentials
                    }
                });
                
                // محاولة إنشاء الاعتماد
                credential = await navigator.credentials.create({
                    publicKey: publicKeyCredentialCreationOptions
                });
                
                console.log('WebAuthn credential created successfully', {
                    id: credential.id ? credential.id.substring(0, 20) + '...' : 'N/A',
                    type: credential.type,
                    rawIdLength: credential.rawId ? credential.rawId.byteLength : 0
                });
            } catch (error) {
                console.error('WebAuthn error:', error);
                console.error('Error name:', error.name);
                console.error('Error message:', error.message);
                console.error('Error stack:', error.stack);
                
                // رسالة خطأ أوضح مع دعم أفضل للأجهزة المختلفة
                let errorMessage = 'فشل في التسجيل البيومتري.';
                
                if (error.name === 'NotAllowedError') {
                    if (isChrome && isAndroid) {
                        errorMessage = 'تم إلغاء العملية أو رفض الطلب.\n\n' +
                            'لحفظ Passkey في حساب Google:\n' +
                            '1. اضغط "Allow" عند ظهور نافذة Google Passkey\n' +
                            '2. اختر حساب Google لحفظ Passkey\n' +
                            '3. تأكد من تفعيل البصمة في إعدادات Android\n' +
                            '4. تأكد من تفعيل Google Password Manager';
                    } else if (isSafari && (isMobile || /Mac/i.test(ua))) {
                        errorMessage = 'تم إلغاء العملية أو رفض الطلب.\n\n' +
                            'لحفظ Passkey في iCloud Keychain:\n' +
                            '1. اضغط "Allow" عند ظهور نافذة Face ID/Touch ID\n' +
                            '2. تأكد من تفعيل iCloud Keychain في إعدادات الجهاز\n' +
                            '3. تأكد من تفعيل Face ID/Touch ID في إعدادات الجهاز';
                    } else {
                        errorMessage = 'تم إلغاء العملية أو رفض الطلب.\n\n' +
                            'تأكد من:\n' +
                            '1. السماح للموقع بالوصول إلى البصمة/المفتاح\n' +
                            '2. الضغط على "Allow" عند ظهور نافذة البصمة\n' +
                            '3. تفعيل Face ID/Touch ID في إعدادات الجهاز';
                    }
                } else if (error.name === 'NotSupportedError') {
                    if (isAndroid && androidVersion && androidVersion < 7.0) {
                        errorMessage = 'Android القديم لا يدعم WebAuthn بشكل كامل.\n\n' +
                            'يرجى استخدام:\n' +
                            '- Android 7.0 أو أحدث\n' +
                            '- Chrome 67 أو أحدث\n' +
                            '- أو متصفح حديث آخر يدعم WebAuthn';
                    } else {
                        errorMessage = 'المتصفح أو الجهاز لا يدعم WebAuthn.\n\n' +
                            'يرجى استخدام:\n' +
                            '- Chrome 67+ (Android/Desktop)\n' +
                            '- Safari 14+ (iOS 14+/macOS)\n' +
                            '- Firefox 60+ (Desktop)\n' +
                            '- Edge 18+ (Desktop)';
                    }
                } else if (error.name === 'InvalidStateError') {
                    errorMessage = 'البصمة أو Passkey مسجلة بالفعل على هذا الجهاز.\n\n' +
                        'يمكنك:\n' +
                        '1. استخدام البصمة الموجودة لتسجيل الدخول\n' +
                        '2. حذف البصمة القديمة من إعدادات الموقع ثم إضافة واحدة جديدة';
                } else if (error.name === 'SecurityError') {
                    errorMessage = 'خطأ أمني.\n\n' +
                        'تأكد من:\n' +
                        '1. أن الموقع يستخدم HTTPS (أو localhost)\n' +
                        '2. أن rpId صحيح\n' +
                        '3. أن الموقع مسموح به في إعدادات الأمان';
                } else {
                    errorMessage = 'فشل في التسجيل البيومتري: ' + (error.message || error.name) + '\n\n' +
                        'تأكد من:\n' +
                        '1. تفعيل البصمة أو Face ID/Touch ID\n' +
                        '2. السماح للموقع بالوصول إلى البصمة\n' +
                        '3. استخدام متصفح حديث يدعم WebAuthn';
                }
                
                throw new Error(errorMessage);
            }

            if (!credential) {
                throw new Error('فشل في إنشاء الاعتماد');
            }

            // 7. تحويل البيانات إلى base64
            const credentialId = this.arrayBufferToBase64(credential.rawId);
            const attestationObject = this.arrayBufferToBase64(credential.response.attestationObject);
            const clientDataJSON = this.arrayBufferToBase64(credential.response.clientDataJSON);
            
            // تسجيل معلومات إضافية للمساعدة في التشخيص
            console.log('Credential created:', {
                id: credential.id ? credential.id.substring(0, 30) + '...' : 'N/A',
                type: credential.type,
                rawIdLength: credential.rawId ? credential.rawId.byteLength : 0,
                attestationObjectLength: credential.response.attestationObject ? credential.response.attestationObject.byteLength : 0,
                clientDataJSONLength: credential.response.clientDataJSON ? credential.response.clientDataJSON.byteLength : 0,
                // معلومات إضافية عن authenticator (إن كانت متاحة)
                authenticatorAttachment: credential.response.getAuthenticatorAttachment ? credential.response.getAuthenticatorAttachment() : 'unknown'
            });

            // 8. إرسال البيانات للتحقق
            const verifyResponse = await fetch(this.apiBase, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'verify',
                    response: {
                        id: credential.id,
                        rawId: credentialId,
                        type: credential.type,
                        response: {
                            clientDataJSON: clientDataJSON,
                            attestationObject: attestationObject
                        },
                        deviceName: deviceName.trim()
                    }
                })
            });

            if (!verifyResponse.ok) {
                // معالجة خاصة لخطأ 401 (Unauthorized)
                if (verifyResponse.status === 401) {
                    const errorData = await verifyResponse.json().catch(() => ({}));
                    const errorMessage = errorData.message || 'انتهت جلسة العمل، يرجى إعادة تسجيل الدخول';
                    throw new Error(errorMessage);
                }
                throw new Error(`خطأ في التحقق: ${verifyResponse.status}`);
            }

            const verifyData = await verifyResponse.json();

            if (!verifyData.success) {
                throw new Error(verifyData.message || verifyData.error || 'فشل التحقق من البصمة');
            }

            // رسالة نجاح محسّنة
            let successMessage = verifyData.message || 'تم تسجيل البصمة بنجاح';
            
            // إضافة معلومات إضافية حسب نوع الجهاز
            if (isChrome && isAndroid) {
                successMessage += '\n\nتم حفظ Passkey في حساب Google. يمكنك استخدامه لتسجيل الدخول من أي جهاز مرتبط بحساب Google.';
            } else if (isSafari && (isMobile || /Mac/i.test(ua))) {
                successMessage += '\n\nتم حفظ Passkey في iCloud Keychain. يمكنك استخدامه لتسجيل الدخول من أي جهاز Apple مرتبط بحساب iCloud.';
            } else if (isMobile) {
                successMessage += '\n\nتم حفظ Passkey على الجهاز. يمكنك استخدامه لتسجيل الدخول بسهولة.';
            }

            return {
                success: true,
                message: successMessage
            };

        } catch (error) {
            console.error('WebAuthn Registration Error:', error);
            
            // معالجة الأخطاء الشائعة
            let errorMessage = 'خطأ في تسجيل البصمة';
            
            if (error.name === 'NotAllowedError') {
                errorMessage = 'تم إلغاء العملية أو رفض الطلب.\n\n' +
                    'تأكد من:\n' +
                    '1. السماح للموقع بالوصول إلى البصمة/المفتاح عند الطلب\n' +
                    '2. الضغط على "Allow" أو "Allow once" عند ظهور نافذة البصمة\n' +
                    '3. تفعيل Face ID/Touch ID في إعدادات الجهاز';
            } else if (error.name === 'NotSupportedError') {
                errorMessage = 'الجهاز أو المتصفح لا يدعم WebAuthn. يرجى استخدام:\n' +
                    '- Chrome 67+\n' +
                    '- Safari 14+ (iOS 14+)\n' +
                    '- Firefox 60+';
            } else if (error.name === 'InvalidStateError') {
                errorMessage = 'البصمة مسجلة بالفعل على هذا الجهاز. احذف البصمة القديمة أولاً.';
            } else if (error.name === 'SecurityError') {
                errorMessage = 'خطأ أمني. تأكد من:\n' +
                    '1. أن الموقع يستخدم HTTPS\n' +
                    '2. أن rpId صحيح\n' +
                    '3. أن الموقع مسموح به في إعدادات الأمان';
            } else if (error.message) {
                errorMessage = error.message;
            }

            return {
                success: false,
                message: errorMessage
            };
        }
    }

    /**
     * الحصول على قائمة المستخدمين الذين لديهم بصمات مسجلة
     */
    async getUsersWithCredentials() {
        try {
            const currentPath = window.location.pathname;
            const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
            
            let loginApiPath;
            if (pathParts.length === 0) {
                loginApiPath = 'api/webauthn_login.php';
            } else {
                loginApiPath = '/' + pathParts[0] + '/api/webauthn_login.php';
            }

            const response = await fetch(loginApiPath, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin',
                body: new URLSearchParams({
                    action: 'get_users_with_credentials'
                })
            });

            if (!response.ok) {
                return { success: false, users: [] };
            }

            const data = await response.json();
            return data;
        } catch (error) {
            console.error('Error getting users with credentials:', error);
            return { success: false, users: [] };
        }
    }

    /**
     * عرض قائمة بالحسابات للاختيار
     */
    showAccountSelectionModal(users) {
        return new Promise((resolve, reject) => {
            // التحقق من أن Bootstrap متاح
            if (typeof bootstrap === 'undefined') {
                // إذا لم يكن Bootstrap متاحاً، نستخدم prompt بسيط
                const usernames = users.map(u => u.username);
                const userList = users.map((u, i) => `${i + 1}. ${u.full_name || u.username} (${u.username})`).join('\n');
                const choice = prompt(`تم العثور على أكثر من حساب مرتبط بالبصمة. يرجى اختيار الحساب:\n\n${userList}\n\nأدخل رقم الحساب (1-${users.length}):`);
                
                if (choice === null || choice === '') {
                    reject(new Error('تم إلغاء العملية'));
                    return;
                }
                
                const index = parseInt(choice) - 1;
                if (index >= 0 && index < users.length) {
                    resolve(users[index].username);
                } else {
                    reject(new Error('اختيار غير صحيح'));
                }
                return;
            }
            
            // إنشاء modal للاختيار
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'accountSelectionModal';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'accountSelectionModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            
            // ترجمة الأدوار
            const roleNames = {
                'accountant': 'محاسب',
                'sales': 'مبيعات',
                'production': 'إنتاج',
                'manager': 'مدير'
            };
            
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="accountSelectionModalLabel">
                                <i class="bi bi-person-check me-2"></i>اختر الحساب
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted mb-3">تم العثور على أكثر من حساب مرتبط بالبصمة. يرجى اختيار الحساب الذي تريد الدخول إليه:</p>
                            <div class="list-group" id="accountList">
                                ${users.map((user, index) => `
                                    <button type="button" class="list-group-item list-group-item-action account-item" data-username="${user.username}" data-index="${index}">
                                        <div class="d-flex w-100 justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-1">${user.full_name || user.username}</h6>
                                                <small class="text-muted">${user.username}</small>
                                            </div>
                                            <span class="badge bg-primary rounded-pill">${roleNames[user.role] || user.role}</span>
                                        </div>
                                    </button>
                                `).join('')}
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
            
            // إضافة event listeners
            modal.querySelectorAll('.account-item').forEach(item => {
                item.addEventListener('click', () => {
                    const username = item.getAttribute('data-username');
                    bsModal.hide();
                    setTimeout(() => {
                        modal.remove();
                        resolve(username);
                    }, 300);
                });
            });
            
            modal.addEventListener('hidden.bs.modal', () => {
                modal.remove();
                reject(new Error('تم إلغاء العملية'));
            });
        });
    }

    /**
     * تسجيل الدخول باستخدام WebAuthn بدون اسم مستخدم
     * يتحقق أولاً من البصمة على الجهاز، ثم يعرض الحسابات المرتبطة بها
     */
    async loginWithoutUsername() {
        try {
            // التحقق من الدعم
            if (!this.isSupported()) {
                throw new Error('WebAuthn غير مدعوم في هذا المتصفح. يرجى استخدام متصفح حديث.');
            }

            // التحقق من HTTPS (أكثر مرونة للموبايل)
            const isLocalhost = window.location.hostname === 'localhost' || 
                               window.location.hostname === '127.0.0.1' ||
                               window.location.hostname.startsWith('192.168.') ||
                               window.location.hostname.startsWith('10.') ||
                               window.location.hostname.startsWith('172.');
            
            if (window.location.protocol !== 'https:' && !isLocalhost) {
                // على الموبايل، قد يعمل HTTP في بعض الحالات
                console.warn('WebAuthn يتطلب HTTPS عادة، لكن سنحاول المتابعة...');
            }

            // الحصول على مسار API لتسجيل الدخول
            const currentPath = window.location.pathname;
            const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
            
            let loginApiPath;
            if (pathParts.length === 0) {
                loginApiPath = 'api/webauthn_login.php';
            } else {
                loginApiPath = '/' + pathParts[0] + '/api/webauthn_login.php';
            }
            
            // 1. أولاً: الحصول على challenge بدون اسم مستخدم للتحقق من البصمة
            const challengeResponse = await fetch(loginApiPath, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin',
                body: new URLSearchParams({
                    action: 'create_challenge_without_username'
                })
            });

            if (!challengeResponse.ok) {
                const errorText = await challengeResponse.text();
                throw new Error(`خطأ في الاتصال بالخادم: ${challengeResponse.status} - ${errorText}`);
            }

            const challengeData = await challengeResponse.json();

            if (!challengeData.success || !challengeData.challenge) {
                throw new Error(challengeData.error || 'فشل في إنشاء التحدي');
            }

            const challenge = challengeData.challenge;

            // 2. تحويل البيانات
            challenge.challenge = this.base64ToArrayBuffer(challenge.challenge);

            // 3. إعداد rpId
            let rpId = challenge.rpId || window.location.hostname;
            rpId = rpId.replace(/^www\./, '').split(':')[0];
            challenge.rpId = rpId;

            // 4. إعدادات للموبايل
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            if (isMobile) {
                challenge.timeout = 180000; // 3 دقائق للموبايل
                challenge.userVerification = 'preferred';
            }

            // 5. الحصول على الاعتماد (بدون allowCredentials - سيطلب من المستخدم التحقق من البصمة)
            // هذا سيظهر جميع البصمات المسجلة على الجهاز لهذا الموقع فقط
            let credential;
            try {
                credential = await navigator.credentials.get({
                    publicKey: challenge
                });
            } catch (error) {
                console.error('WebAuthn get error:', error);
                
                // معالجة الأخطاء الشائعة
                if (error.name === 'NotAllowedError') {
                    throw new Error('تم رفض أو إلغاء العملية. يرجى المحاولة مرة أخرى والتأكد من السماح للموقع بالوصول إلى البصمة.');
                } else if (error.name === 'NotSupportedError') {
                    throw new Error('الجهاز أو المتصفح لا يدعم WebAuthn. يرجى استخدام متصفح حديث.');
                } else if (error.name === 'InvalidStateError') {
                    throw new Error('لا توجد بصمة مسجلة على هذا الجهاز لهذا الموقع.');
                } else if (error.name === 'SecurityError') {
                    throw new Error('خطأ أمني. تأكد من أن الموقع يستخدم HTTPS.');
                } else {
                    throw new Error('فشل في التحقق من البصمة: ' + (error.message || error.name));
                }
            }

            if (!credential) {
                throw new Error('لم يتم العثور على بصمة مسجلة على هذا الجهاز.');
            }

            // 6. تحويل البيانات
            const clientDataJSON = this.arrayBufferToBase64(credential.response.clientDataJSON);
            const authenticatorData = this.arrayBufferToBase64(credential.response.authenticatorData);
            const signature = this.arrayBufferToBase64(credential.response.signature);
            const credentialIdBase64 = this.arrayBufferToBase64(credential.rawId);

            // 7. التحقق من البصمة
            const verifyResponse = await fetch(loginApiPath, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin',
                body: new URLSearchParams({
                    action: 'verify_without_username',
                    response: JSON.stringify({
                        id: credential.id,
                        rawId: credentialIdBase64,
                        type: credential.type,
                        response: {
                            clientDataJSON: clientDataJSON,
                            authenticatorData: authenticatorData,
                            signature: signature
                        }
                    })
                })
            });

            if (!verifyResponse.ok) {
                const errorText = await verifyResponse.text();
                throw new Error(`خطأ في التحقق: ${verifyResponse.status} - ${errorText}`);
            }

            const verifyData = await verifyResponse.json();

            if (verifyData.success) {
                // حفظ معلومات الحساب في localStorage عند تسجيل الدخول الناجح
                if (verifyData.user) {
                    try {
                        const storageKey = 'webauthn_device_accounts';
                        let accounts = [];
                        
                        try {
                            const stored = localStorage.getItem(storageKey);
                            if (stored) {
                                accounts = JSON.parse(stored);
                                if (!Array.isArray(accounts)) {
                                    accounts = [];
                                }
                            }
                        } catch (e) {
                            accounts = [];
                        }
                        
                        const accountData = {
                            user_id: verifyData.user.id,
                            username: verifyData.user.username,
                            full_name: verifyData.user.full_name || verifyData.user.username,
                            role: verifyData.user.role,
                            last_login: new Date().toISOString()
                        };
                        
                        // التحقق من عدم وجود الحساب مسبقاً
                        const existingIndex = accounts.findIndex(acc => 
                            (acc.user_id && acc.user_id === accountData.user_id) || 
                            acc.username === accountData.username
                        );
                        
                        if (existingIndex >= 0) {
                            accounts[existingIndex] = accountData;
                        } else {
                            accounts.push(accountData);
                        }
                        
                        // تنظيف الحسابات القديمة (أكثر من 10 حسابات)
                        if (accounts.length > 10) {
                            accounts = accounts.slice(-10);
                        }
                        
                        localStorage.setItem(storageKey, JSON.stringify(accounts));
                        console.log('Saved account to localStorage:', accountData.username, 'Total accounts:', accounts.length);
                    } catch (storageError) {
                        console.warn('Could not save account to localStorage:', storageError);
                        // محاولة استخدام sessionStorage كبديل
                        try {
                            sessionStorage.setItem(storageKey + '_fallback', JSON.stringify([accountData]));
                            console.log('Saved to sessionStorage as fallback');
                        } catch (fallbackError) {
                            console.error('Failed to save to sessionStorage too:', fallbackError);
                        }
                    }
                }
                
                // إعادة توجيه إلى لوحة التحكم
                const userRole = verifyData.user?.role || 'accountant';
                
                let dashboardUrl;
                if (pathParts.length === 0) {
                    dashboardUrl = 'dashboard/' + userRole + '.php';
                } else {
                    dashboardUrl = '/' + pathParts[0] + '/dashboard/' + userRole + '.php';
                }
                
                window.location.href = dashboardUrl;
                return {
                    success: true,
                    message: 'تم تسجيل الدخول بنجاح',
                    redirect: dashboardUrl,
                    user: verifyData.user
                };
            } else {
                throw new Error(verifyData.error || 'فشل التحقق من البصمة');
            }

        } catch (error) {
            console.error('WebAuthn Login Without Username Error:', error);
            
            let errorMessage = 'خطأ في تسجيل الدخول';
            
            // إذا كانت رسالة الخطأ موجودة ومفيدة، نستخدمها
            if (error.message && error.message !== 'خطأ في تسجيل الدخول') {
                errorMessage = error.message;
            } else if (error.name === 'NotAllowedError') {
                errorMessage = 'تم رفض أو إلغاء العملية. يرجى المحاولة مرة أخرى والتأكد من السماح للموقع بالوصول إلى البصمة.';
            } else if (error.name === 'NotSupportedError') {
                errorMessage = 'الجهاز أو المتصفح لا يدعم WebAuthn. يرجى استخدام متصفح حديث مثل Chrome أو Safari.';
            } else if (error.name === 'InvalidStateError') {
                errorMessage = 'لا توجد بصمة مسجلة على هذا الجهاز. يرجى تسجيل بصمة أولاً.';
            } else if (error.name === 'SecurityError') {
                errorMessage = 'خطأ أمني. تأكد من أن الموقع يستخدم HTTPS.';
            } else if (error.message && error.message.includes('HTTPS')) {
                // على الموبايل، قد يعمل HTTP في بعض الحالات
                errorMessage = 'WebAuthn يتطلب HTTPS عادة. إذا كنت على شبكة محلية، قد يعمل HTTP.';
            } else if (error.message && (error.message.includes('تم إلغاء') || error.message.includes('رفض'))) {
                errorMessage = error.message;
            }

            throw new Error(errorMessage);
        }
    }

    /**
     * تسجيل الدخول باستخدام WebAuthn
     */
    async login(username) {
        try {
            // التحقق من الدعم
            if (!this.isSupported()) {
                throw new Error('WebAuthn غير مدعوم في هذا المتصفح. يرجى استخدام متصفح حديث.');
            }

            // التحقق من HTTPS
            if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
                throw new Error('WebAuthn يتطلب HTTPS. الموقع الحالي: ' + window.location.protocol);
            }

            if (!username) {
                throw new Error('اسم المستخدم مطلوب');
            }

            // الحصول على مسار API لتسجيل الدخول - استخدام مسار مطلق
            const currentPath = window.location.pathname;
            const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
            
            let loginApiPath;
            if (pathParts.length === 0) {
                // في الجذر
                loginApiPath = 'api/webauthn_login.php';
            } else {
                // في مجلد فرعي - استخدام مسار مطلق
                loginApiPath = '/' + pathParts[0] + '/api/webauthn_login.php';
            }
            
            console.log('WebAuthn Login API path:', loginApiPath, 'Path parts:', pathParts);

            // 1. الحصول على challenge
            const challengeResponse = await fetch(loginApiPath, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin',
                body: new URLSearchParams({
                    action: 'create_challenge',
                    username: username
                })
            });
            
            console.log('Challenge response status:', challengeResponse.status);

            if (!challengeResponse.ok) {
                throw new Error(`خطأ في الاتصال بالخادم: ${challengeResponse.status}`);
            }

            const challengeData = await challengeResponse.json();

            if (!challengeData.success || !challengeData.challenge) {
                throw new Error(challengeData.error || 'لا توجد بصمات مسجلة لهذا المستخدم');
            }

            const challenge = challengeData.challenge;

            // 2. تحويل البيانات
            challenge.challenge = this.base64ToArrayBuffer(challenge.challenge);

            if (challenge.allowCredentials && Array.isArray(challenge.allowCredentials)) {
                challenge.allowCredentials = challenge.allowCredentials.map(cred => ({
                    id: this.base64ToArrayBuffer(cred.id),
                    type: cred.type || 'public-key'
                })).filter(cred => cred !== null);
            }

            // 3. إعداد rpId
            let rpId = challenge.rpId || window.location.hostname;
            rpId = rpId.replace(/^www\./, '').split(':')[0];
            challenge.rpId = rpId;

            // 4. إعدادات للموبايل
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            if (isMobile) {
                challenge.timeout = 180000;
                challenge.userVerification = 'preferred';
            }

            if (!challenge.allowCredentials || challenge.allowCredentials.length === 0) {
                throw new Error('لا توجد بصمات مسجلة لهذا المستخدم');
            }

            // 5. الحصول على الاعتماد
            const credential = await navigator.credentials.get({
                publicKey: challenge
            });

            if (!credential) {
                throw new Error('فشل في الحصول على الاعتماد');
            }

            // 6. تحويل البيانات
            const clientDataJSON = this.arrayBufferToBase64(credential.response.clientDataJSON);
            const authenticatorData = this.arrayBufferToBase64(credential.response.authenticatorData);
            const signature = this.arrayBufferToBase64(credential.response.signature);
            const credentialIdBase64 = this.arrayBufferToBase64(credential.rawId);

            // 7. التحقق من البصمة
            const verifyResponse = await fetch(loginApiPath, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin',
                body: new URLSearchParams({
                    action: 'verify',
                    response: JSON.stringify({
                        id: credential.id,
                        rawId: credentialIdBase64,
                        type: credential.type,
                        response: {
                            clientDataJSON: clientDataJSON,
                            authenticatorData: authenticatorData,
                            signature: signature
                        }
                    })
                })
            });
            
            console.log('Verify response status:', verifyResponse.status, verifyResponse.statusText);

            if (!verifyResponse.ok) {
                const errorText = await verifyResponse.text();
                console.error('Verify error response:', errorText);
                throw new Error(`خطأ في التحقق: ${verifyResponse.status} - ${errorText}`);
            }

            const verifyData = await verifyResponse.json();
            console.log('Verify data:', verifyData);

            if (verifyData.success) {
                // إعادة توجيه إلى لوحة التحكم - استخدام مسار مطلق
                const userRole = verifyData.user?.role || 'accountant';
                
                // حساب المسار بناءً على موقع الصفحة الحالية
                const currentPath = window.location.pathname;
                const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
                
                let dashboardUrl;
                if (pathParts.length === 0) {
                    // في الجذر
                    dashboardUrl = 'dashboard/' + userRole + '.php';
                } else {
                    // في مجلد فرعي - استخدام مسار مطلق
                    dashboardUrl = '/' + pathParts[0] + '/dashboard/' + userRole + '.php';
                }
                
                console.log('Redirecting to dashboard:', dashboardUrl);
                window.location.href = dashboardUrl;
                return {
                    success: true,
                    message: 'تم تسجيل الدخول بنجاح',
                    redirect: dashboardUrl
                };
            } else {
                throw new Error(verifyData.error || 'فشل التحقق من البصمة');
            }

        } catch (error) {
            console.error('WebAuthn Login Error:', error);
            
            let errorMessage = 'خطأ في تسجيل الدخول';
            if (error.message) {
                errorMessage = error.message;
            } else if (error.name === 'NotAllowedError') {
                errorMessage = 'تم إلغاء العملية. يرجى المحاولة مرة أخرى.';
            } else if (error.name === 'NotSupportedError') {
                errorMessage = 'الجهاز أو المتصفح لا يدعم WebAuthn';
            }

            alert(errorMessage);
            return false;
        }
    }
}

// إنشاء كائن عام
var simpleWebAuthn = new SimpleWebAuthn();

// للتوافق مع الكود القديم
var webauthnManager = {
    login: function(username) {
        return simpleWebAuthn.login(username);
    },
    loginWithoutUsername: function() {
        return simpleWebAuthn.loginWithoutUsername();
    },
    register: function() {
        return simpleWebAuthn.register();
    }
};

// التأكد من أن webauthnManager متاح بشكل عام
if (typeof window !== 'undefined') {
    window.webauthnManager = webauthnManager;
    window.simpleWebAuthn = simpleWebAuthn;
}

// أيضاً جعله متاحاً بشكل عام (للمتصفحات القديمة)
if (typeof global !== 'undefined') {
    global.webauthnManager = webauthnManager;
    global.simpleWebAuthn = simpleWebAuthn;
}

// للتأكد من التوفر الفوري
console.log('WebAuthn initialized:', {
    simpleWebAuthn: typeof simpleWebAuthn !== 'undefined',
    webauthnManager: typeof webauthnManager !== 'undefined',
    loginWithoutUsername: typeof simpleWebAuthn !== 'undefined' && typeof simpleWebAuthn.loginWithoutUsername === 'function'
});

