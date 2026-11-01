# DKIM-Testschlüssel

Nur für die lokale Testzone `smartmailflow.test` (existiert nicht im Internet).
Bewusst im Repo, damit Tests reproduzierbar sind. **Niemals für echte Domains verwenden.**

| Datei | Selector | Zweck |
|---|---|---|
| s1024.key | s1024 | RSA 1024 |
| s2048.key | s2048 | RSA 2048 (Standard) |
| s4096.key | s4096 | RSA 4096 |
| ed25519.key | ed25519 | Ed25519 (RFC 8463) |
| other.key | – | „falscher“ Schlüssel: signiert mit Selector s2048 → muss fail ergeben |

## S/MIME-Testzertifikate (ab 2.1.0)
Nur für Tests, Schlüssel bewusst im Repo. Die Test-CA ist nirgends als vertrauenswürdig hinterlegt
(Analysetests übergeben sie als `smime_cafile`, auf dem Server ergibt sie daher `chain_trusted: false`).

| Datei | Zweck |
|---|---|
| smime-ca.pem / .key | Test-CA „SmartMailFlow Test S-MIME CA“ (10 Jahre) |
| smime-signer.pem / .key | von der Test-CA ausgestellt, E-Mail-Schutz, für ok@smartmailflow.test und ok@example.test |
| smime-self.pem / .key | selbstsigniert, ok@smartmailflow.test |
