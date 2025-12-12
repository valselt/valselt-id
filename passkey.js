// Helper: Konversi ArrayBuffer ke Base64URL (Diperlukan WebAuthn)
function bufferToBase64url(buffer) {
    const bytes = new Uint8Array(buffer);
    let str = '';
    for (const char of bytes) str += String.fromCharCode(char);
    return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

// Helper: Konversi Base64URL ke ArrayBuffer
function base64urlToBuffer(base64) {
    // 1. Ubah Base64URL (-) kembali ke Base64 Standar (+) jika perlu
    // Tapi karena PHP kirim standard, baris ini jaga-jaga saja
    var binary_string = window.atob(base64.replace(/-/g, "+").replace(/_/g, "/"));
    var len = binary_string.length;
    var bytes = new Uint8Array(len);
    for (var i = 0; i < len; i++) {
        bytes[i] = binary_string.charCodeAt(i);
    }
    return bytes.buffer;
}

let tempAttestation = null;

// --- FUNGSI REGISTER PASSKEY ---
async function registerPasskey() {
    try {
        // 1. Minta Challenge
        const rep = await fetch('passkey_api.php?fn=getRegisterArgs');
        const args = await rep.json();

        if (args.status === 'error') throw new Error(args.message);

        // Konversi format
        args.publicKey.user.id = base64urlToBuffer(args.publicKey.user.id);
        args.publicKey.challenge = base64urlToBuffer(args.publicKey.challenge);
        if (args.publicKey.excludeCredentials) {
            for (let i = 0; i < args.publicKey.excludeCredentials.length; i++) {
                args.publicKey.excludeCredentials[i].id = base64urlToBuffer(args.publicKey.excludeCredentials[i].id);
            }
        }

        // 2. Tampilkan Pop-up Browser/Fingerprint
        const cred = await navigator.credentials.create(args);

        // 3. SIMPAN DATA KE VARIABEL SEMENTARA
        tempAttestation = {
            clientDataJSON: bufferToBase64url(cred.response.clientDataJSON),
            attestationObject: bufferToBase64url(cred.response.attestationObject)
        };

        // 4. BUKA MODAL INPUT NAMA (Jangan kirim ke server dulu)
        document.getElementById('passkey_name_input').value = ""; // Reset input
        openModal('modalPasskeyName');
        
        // Fokus ke input field setelah modal muncul
        setTimeout(() => document.getElementById('passkey_name_input').focus(), 100);

    } catch (e) {
        alert("Batal / Gagal membuat Passkey: " + e.message);
    }
}

async function submitPasskeyData() {
    if (!tempAttestation) return;

    const customName = document.getElementById('passkey_name_input').value;
    const btn = document.querySelector('#modalPasskeyName .popup-btn.success');
    
    // Tambahkan nama ke objek data
    tempAttestation.passkeyName = customName;

    btn.innerText = "Menyimpan...";
    btn.disabled = true;

    try {
        const verifyRep = await fetch('passkey_api.php?fn=processRegister', {
            method: 'POST',
            body: JSON.stringify(tempAttestation)
        });
        
        const verifyResult = await verifyRep.json();
        
        if (verifyResult.status === 'success') {
            closeModal('modalPasskeyName');
            // Refresh halaman atau load ulang list passkey
            window.location.reload(); 
        } else {
            alert("Gagal: " + verifyResult.message);
            btn.innerText = "Simpan";
            btn.disabled = false;
        }
    } catch (e) {
        alert("Error Server: " + e.message);
        btn.innerText = "Simpan";
        btn.disabled = false;
    }
}

// --- FUNGSI LOGIN PASSKEY ---
async function loginPasskey() {
    try {
        // 1. Minta Challenge Login
        const rep = await fetch('passkey_api.php?fn=getLoginArgs');
        const args = await rep.json();

        // Konversi format
        args.publicKey.challenge = base64urlToBuffer(args.publicKey.challenge);
        if (args.publicKey.allowCredentials) {
            for (let i = 0; i < args.publicKey.allowCredentials.length; i++) {
                args.publicKey.allowCredentials[i].id = base64urlToBuffer(args.publicKey.allowCredentials[i].id);
            }
        }

        // 2. Pop-up Fingerprint
        const cred = await navigator.credentials.get(args);

        // 3. Kirim ke Server
        const authObj = {
            id: cred.id,
            clientDataJSON: bufferToBase64url(cred.response.clientDataJSON),
            authenticatorData: bufferToBase64url(cred.response.authenticatorData),
            signature: bufferToBase64url(cred.response.signature),
            userHandle: cred.response.userHandle ? bufferToBase64url(cred.response.userHandle) : null
        };

        const verifyRep = await fetch('passkey_api.php?fn=processLogin', {
            method: 'POST',
            body: JSON.stringify(authObj)
        });

        const verifyResult = await verifyRep.json();

        if (verifyResult.status === 'success') {
            window.location.href = "index.php"; // Redirect jika sukses
        } else {
            alert("Login Gagal: " + verifyResult.message);
        }

    } catch (e) {
        alert("Gagal Login Passkey: " + e.message);
    }
}