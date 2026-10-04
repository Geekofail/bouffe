# Accès à Bouffe depuis l'extérieur (point 20.1)

Bouffe tourne sur le PC de la maison, sous WampServer. Sur le Wi-Fi de la maison, on y accède en
`http://192.168.x.x` : c'est simple et suffisant. Deux choses ne fonctionnent pourtant qu'en **HTTPS** :

- l'installation sur l'écran d'accueil du téléphone et le **service worker** (donc le rechargement
  de la liste de courses sans réseau — voir lot 16) ;
- le maintien de l'**écran allumé** en mode cuisine et en mode magasin.

Et surtout : depuis le magasin ou le bureau, le PC de la maison n'est pas joignable du tout.

## La solution retenue : un tunnel privé (Tailscale)

**Question Q16 laissée sans réponse ; la proposition par défaut a donc été appliquée : option B,
tunnel privé Tailscale.** Le code fonctionne aussi avec les options C (reverse proxy + nom de
domaine) et D (hébergement extérieur) : rien dans Bouffe n'y est lié, seuls les réglages changent.

Pourquoi celle-ci : rien n'est ouvert sur Internet (pas de redirection de port sur la box), le HTTPS
est fourni et renouvelé tout seul, et seuls les appareils que tu as ajoutés voient Bouffe.

### Mise en place, une fois

1. Créer un compte sur [tailscale.com](https://tailscale.com) (gratuit pour un usage personnel).
2. Installer Tailscale **sur le PC de la maison** et se connecter avec ce compte.
3. Installer Tailscale **sur les téléphones** (le tien et celui de Monique), même compte.
4. Sur le PC, activer le nom HTTPS :

   ```powershell
   tailscale cert
   tailscale serve --bg --https=443 http://localhost:80
   ```

   Tailscale donne alors une adresse du type
   `https://mon-pc.mon-reseau.ts.net` — c'est celle-là qu'on ouvre sur les téléphones.

5. Dans `C:\wamp64\www\Bouffe\src\.env` :

   ```ini
   APP_URL=https://mon-pc.mon-reseau.ts.net
   BOUFFE_TRUSTED_PROXIES="*"
   BOUFFE_FORCE_HTTPS=true
   SESSION_SECURE_COOKIE=true
   ```

   Puis :

   ```powershell
   cd C:\wamp64\www\Bouffe\src
   php artisan config:clear
   ```

6. Sur le téléphone : ouvrir l'adresse, puis « Ajouter à l'écran d'accueil ». Bouffe s'ouvre alors
   comme une application, et la liste de courses fonctionne en magasin même sans réseau.

### Pourquoi ces trois réglages

- `BOUFFE_TRUSTED_PROXIES` : c'est Tailscale qui termine le HTTPS ; Apache reçoit la requête en
  `http://`. Sans ce réglage, Laravel fabrique des liens en `http://` et les formulaires cassent.
- `BOUFFE_FORCE_HTTPS` : tous les liens sont écrits en `https://`.
- `SESSION_SECURE_COOKIE` : le cookie de session n'est plus envoyé en clair.

⚠️ Ne mets `BOUFFE_FORCE_HTTPS=true` **qu'une fois le tunnel en place** : sinon l'accès en
`http://192.168.x.x` sur le Wi-Fi renverra des liens `https://` injoignables.

## Les autres options envisagées

| Option | Principe | Pourquoi pas retenue |
|---|---|---|
| **A** | Rien : accès Wi-Fi seulement | Ne répond pas au besoin en magasin |
| **B** ✅ | Tunnel privé Tailscale | Retenue : rien d'ouvert sur Internet, HTTPS inclus |
| **C** | Nom de domaine + reverse proxy (Caddy) + redirection de port | Expose le PC de la maison sur Internet ; à réserver si tu veux une vraie adresse publique |
| **D** | Héberger Bouffe chez un hébergeur | Les données quittent la maison, et il faut payer tous les mois |

## Vérifier que tout est en place

Paramètres → **Diagnostic** :

- « Accès chiffré (HTTPS) » doit passer au vert ;
- « Mode débogage » doit être désactivé (`APP_DEBUG=false`) pour un accès depuis l'extérieur ;
- « Blocage après essais ratés » rappelle combien d'essais de connexion sont tolérés.
