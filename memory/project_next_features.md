---
name: project-next-features
description: Funzionalità pianificate per i prossimi update di Cetus Media Optimizer
metadata:
  type: project
---

## Prossimo big update

**Supporto formato HEIC/HEIF in input**

**Why:** Gli iPhone scattano in HEIC di default — molti utenti caricano HEIC su WordPress senza saperlo. Imagick già legge HEIC su server Aruba (confermato dalla diagnosi: `image/heic → image/jpeg`).

**How to apply:** Nel prossimo major update aggiungere:
- `image/heic` e `image/heif` a `SUPPORTED_MIME` in `class-cetus-optimizer.php`
- Gestione estensione `.heic`/`.heif` in `build_output_path()`
- Escludere o avvisare per `image/heic-sequence` (Live Photos iPhone — sequenze animate)
- Aggiornare la Diagnosi Server con una riga dedicata al supporto HEIC in lettura
