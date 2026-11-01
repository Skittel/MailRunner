"""Encryption at rest with the node key (XChaCha20-Poly1305, libsodium).

File format v1:  b"MRE1" | nonce (24 bytes) | ciphertext+tag
Associated data: b"MRE1" + context, e.g. b"spool:<id>:eml". The context binds a file to its
purpose and id, so files cannot be swapped unnoticed. PHP counterpart: App\\Core\\Secrets\\NodeCrypto.
"""
import base64
import os

from nacl import bindings

MAGIC = b"MRE1"
NONCE_SIZE = bindings.crypto_aead_xchacha20poly1305_ietf_NPUBBYTES  # 24
KEY_SIZE = bindings.crypto_aead_xchacha20poly1305_ietf_KEYBYTES     # 32


class CryptoError(Exception):
    pass


def load_key(path):
    with open(path, "rb") as fh:
        key = base64.b64decode(fh.read().strip(), validate=True)
    if len(key) != KEY_SIZE:
        raise CryptoError(f"node key must be {KEY_SIZE} bytes")
    return key


def encrypt(key, plaintext, context, nonce=None):
    nonce = nonce if nonce is not None else os.urandom(NONCE_SIZE)
    ct = bindings.crypto_aead_xchacha20poly1305_ietf_encrypt(plaintext, MAGIC + context.encode(), nonce, key)
    return MAGIC + nonce + ct


def decrypt(key, blob, context):
    if blob[:4] != MAGIC or len(blob) < 4 + NONCE_SIZE + 16:
        raise CryptoError("not a MailRunner encrypted blob")
    nonce = blob[4:4 + NONCE_SIZE]
    try:
        return bindings.crypto_aead_xchacha20poly1305_ietf_decrypt(blob[4 + NONCE_SIZE:], MAGIC + context.encode(), nonce, key)
    except Exception as e:
        raise CryptoError("decryption failed (wrong key, context or damaged file)") from e
