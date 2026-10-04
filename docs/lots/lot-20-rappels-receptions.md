# Lot 20 — Rappels et réceptions

**Objectif** : être prévenu au bon moment ; recevoir sereinement.

**Statut** : ✅ développé et vérifié en local (700 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur 1280 px et iPhone 390 px, mode clair et mode sombre) — ⏳ à valider sur ton poste.

Spécification : [06-version-2.md](../06-version-2.md), 13.8, 14.8, module 19 (19.1 à 19.4), module 21 et règle **R21** (ligne « réception »).

## Mise à jour depuis le lot 19

Ferme ton éditeur, puis :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
php artisan bouffe:reminders --tache-windows
```

La dernière commande **ne crée rien** : elle affiche la ligne `schtasks …` à coller dans un
PowerShell **ouvert en administrateur**. La tâche lance `bouffe:reminders` toutes les 10 minutes,
sans fenêtre, tant que l'ordinateur est allumé. Sans elle, ni notification ni récapitulatif.

Aucune dépendance nouvelle : ni `composer install` ni `npm` à relancer.

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 13.8 | **Sous-recettes** | « 1 × Pâte brisée » dans une quiche : ingrédients dépliés pour les courses, le coût, la nutrition, le retrait du stock, les allergies des invités et le mode cuisine ; lien dans les étapes ; suppression interdite tant qu'elle sert ; boucles refusées | ✅ |
| 14.8 | **Batch cooking** | Planning → « En avance » : choix des repas, ingrédients additionnés, recettes de la plus longue à la plus courte, chaque plat rangé au réfrigérateur ou au congélateur **et lié à son repas** | ✅ |
| 19.1 | Centre de notifications (fin) | La cloche signale aussi une liste de courses préparée par l'autre et les restes au réfrigérateur sans repas prévu | ✅ |
| 19.2 | **Notifications sur le téléphone** | Web Push chiffré, abonnement par appareil, choix par personne (Q25 : préparation et péremption cochés par défaut), rien entre 22 h et 7 h | ✅ |
| 19.3 | **Récapitulatif par e-mail** | Le dimanche à 18 h (réglable) : réceptions, menu de la semaine, liste de courses, produits à consommer | ✅ |
| 19.4 | **Tâche planifiée** | `php artisan bouffe:reminders` (+ `--tache-windows`), entrée dans le planificateur Laravel, suivi dans Diagnostic | ✅ |
| 21.1 | **Menu de réception** | Apéritif, entrée, plat, fromage, dessert dans une même case, portions par plat | ✅ |
| 21.2 | **Rétroplanning** | Des courses (J-2) à la mise en cuisson, à cocher ; les étapes lointaines deviennent des rappels | ✅ |
| 21.3 | **Carte de menu** | Page A5 à imprimer (style classique ou moderne), texte à partager | ✅ |
| 21.4 | **Souvenirs** | Photo et note, visibles sur la fiche de chaque invité | ✅ |
| 20.9 | Tests | +42 tests — 700 au total | ✅ |

## 13.8 — sous-recettes

Dans le formulaire d'une recette, bloc **Sous-recettes** : « 1 × Pâte brisée, pour le fond de tarte »
(« 0,5 » = la moitié). La pâte n'est pas recopiée : si tu la corriges, toutes les tartes suivent.

- Liste de courses : la farine de la pâte est additionnée aux autres, avec la provenance
  « Quiche lorraine (pâte brisée) ». Quiche pour 12 au lieu de 6 → deux pâtes.
- Une allergie d'invité est détectée **aussi dans la sous-recette** (c'est le point le plus important).
- Fiche : bloc « Sous-recettes » dépliable, mention « À préparer d'abord », et le nom de la
  sous-recette cité dans une étape devient un lien.
- Une recette qui sert de sous-recette ne se supprime pas (on l'archive) ; une recette ne peut
  pas s'utiliser elle-même, même par un détour.

*Écart avec le document 06* : une table `recipe_components` plutôt qu'une colonne
`recipe_ingredients.sub_recipe_id` — sinon, une « ligne d'ingrédient sans ingrédient » aurait dû
être ignorée partout dans le code existant.

## 14.8 — cuisiner en avance

1. Planning → **En avance** : les recettes des dix prochains jours, ni mangées ni déjà préparées.
2. La session additionne les ingrédients (par rayon) et range les recettes de la plus longue à la
   plus courte : ce qui mijote cuit pendant qu'on prépare le reste.
3. « C'est prêt » range le plat : **réfrigérateur** s'il est mangé dans les 3 jours, **congélateur**
   sinon (modifiable), et retire ses ingrédients du stock (case à décocher).

Ensuite, tout suit : le plat ne revient plus dans la liste de courses ; « mangé » retire le plat
préparé (et plus ses ingrédients) — ce qui dépasse devient des restes ; un plat au congélateur
déclenche « Décongeler » la veille (R21). Décocher « mangé » remet le plat, sans le faire disparaître.

## Module 21 — réceptions

Une réception, c'est une case du planning avec des invités ou un nom d'occasion. Tu y arrives par
**Invités → Réceptions**, par l'icône 🎂 d'une case du planning, ou par la fenêtre « Convives ».

**Le rétroplanning** part de l'heure du repas (l'arrivée des invités) et de la place de chaque plat
(indicatif : entrée +30 min, plat +1 h, fromage +1 h 45, dessert +2 h) :

- courses deux jours avant, 18 h ;
- les rappels R21 de chaque plat (décongeler, tremper, pâte à faire la veille) ;
- **Commencer** un plat = moment où il est servi − préparation − cuisson − repos, mais **jamais après
  l'arrivée des invités pour la partie « mains dans la cuisine »** : seule la cuisson peut tourner
  pendant l'apéritif ;
- **Lancer la cuisson**, quand c'est sans ambiguïté (une recette avec un temps de repos ne dit pas si
  le repos vient avant ou après la cuisson : Bouffe ne devine pas) ;
- sortir le fromage 1 h avant, boissons au frais s'il y a un apéritif, mettre la table.

Les tâches se cochent. Celles prévues au moins 30 min avant le repas deviennent des **rappels**
(cloche et téléphone) — seulement si l'heure du repas est saisie.

**Carte de menu** : « Imprimer » ouvre une page A5 (classique ou moderne, prénoms des invités au
choix) ; « Partager » envoie le menu en texte (ou le copie, sur ordinateur).

**Souvenir** : une photo et quelques mots, affichés sur la fiche de chaque invité et dans la liste
des réceptions passées. La photo est rangée avec celles des recettes : elle est dans les sauvegardes.

## Module 19 — notifications

### Sur le téléphone (19.2)

Paramètres → **Notifications** → « Activer sur cet appareil », puis « Envoyer un essai ».

- **Il faut l'adresse en https** (le tunnel du lot 16) : sur `http://192.168…`, le navigateur refuse.
- **iPhone** : d'abord Safari → Partager → « Sur l'écran d'accueil », ouvrir Bouffe depuis cette
  icône, puis activer (iOS 16.4 ou plus récent). L'écran l'explique s'il détecte un iPhone.
- Chacun choisit ce qu'il reçoit ; par défaut (Q25) : préparation à l'avance et produits à consommer.
- Rien entre 22 h et 7 h : ce qui tombe la nuit part le matin. Un rappel en retard de plus de 12 h
  n'est plus envoyé (ordinateur éteint pendant le week-end).

Le protocole Web Push est implémenté sans bibliothèque (clé VAPID générée une fois, chiffrement
`aes128gcm`). Il a été vérifié contre les bibliothèques de référence `web-push` et `http_ece`
(déchiffrement du message et signature, 40 essais), puis par les tests. Sous Windows, un
`openssl.cnf` minimal est fourni (`resources/openssl/`) car PHP ne trouve pas toujours le sien.

### Par e-mail (19.3)

Il faut un serveur d'envoi. Tant que `MAIL_MAILER=log`, le message est écrit dans
`storage/logs/laravel.log` au lieu d'être envoyé — pratique pour voir à quoi il ressemble.
Exemple pour ton adresse Free, dans `.env` (à vérifier avec l'aide de Free) :

```ini
MAIL_MAILER=smtp
MAIL_HOST=smtp.free.fr
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=ptripodi
MAIL_PASSWORD=ton-mot-de-passe
MAIL_FROM_ADDRESS="ptripodi@free.fr"
```

Puis `php artisan config:clear`. Activation dans Paramètres → Notifications (réglage commun, jour et
heure) + case « Récapitulatif » cochée par chaque personne qui le veut ; bouton « M'envoyer un essai ».

### La tâche planifiée (19.4)

`php artisan bouffe:reminders` recalcule les rappels de la semaine, envoie ce qui est dû et le
récapitulatif le jour venu. Chaque envoi est noté : la lancer deux fois n'envoie rien deux fois.
Paramètres → Notifications et Paramètres → Diagnostic affichent son dernier passage.

Chez un hébergeur, une seule ligne cron suffit : `* * * * * php artisan schedule:run` (la commande
est inscrite au planificateur Laravel, toutes les 10 minutes).

## Fichiers ajoutés

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_02_100000_create_lot20_tables.php` | `recipe_components`, `planned_meals.course` / `prepared_at`, colonnes de `meal_occasions`, `push_subscriptions`, `notification_deliveries` |
| `app/Services/Recipes/SubRecipes.php` | Dépliage des sous-recettes, boucles, liens dans les étapes |
| `app/Services/Planning/BatchCooking.php` | Session, rangement, lien plat ↔ repas |
| `app/Services/Receptions/ReceptionPlanner.php`, `Receptions.php` | Menu, rétroplanning, carte, souvenirs |
| `app/Services/Notifications/WebPush.php` | VAPID et chiffrement |
| `app/Services/Notifications/NotificationDispatcher.php`, `HouseholdNews.php` | Ce qui part, à qui, quand |
| `app/Mail/WeeklyDigest.php` + `resources/views/mail/weekly-digest.blade.php` | Récapitulatif |
| `app/Console/Commands/RemindersCommand.php` | `bouffe:reminders` |
| `app/Livewire/Planner/BatchCook.php`, `Receptions/Index.php`, `Receptions/Show.php`, `Settings/Notifications.php` + vues | Les écrans |
| `app/Http/Controllers/ReceptionController.php`, `PushSubscriptionController.php` | Carte, photo, abonnements |
| `app/Enums/Course.php`, `app/Models/RecipeComponent.php`, `PushSubscription.php` | |
| `resources/openssl/openssl.cnf` | Configuration minimale pour Windows |
| `tests/Feature/Recipes/SubRecipeTest.php`, `Planning/BatchCookingTest.php`, `Receptions/ReceptionTest.php`, `Notifications/NotificationTest.php` | 42 tests |

Fichiers modifiés : courses, coût, nutrition, stock et compatibilité des invités (sous-recettes) ;
`PrepReminderPlanner` (réceptions, plats congelés, heure du repas) ; `MealStockService` (plat préparé) ;
formulaire et fiche recette, mode cuisine ; planning (plat, place dans le menu, 🎂, « En avance ») ;
fenêtre Convives ; fiche invité ; cloche ; `public/sw.js` (notifications, cache v2) ; `resources/js/app.js` ;
Diagnostic ; `config/bouffe.php` ; `.env.example`.

## À vérifier sur ton poste

- `bouffe:deploy`, puis créer la tâche planifiée ; Diagnostic doit afficher « Dernier passage il y a … ».
- **Sous-recette** : crée « Pâte brisée », ajoute-la à ta quiche, génère la liste de courses de la semaine.
- **Réception** : Invités → Réceptions → « Organiser », compose le menu, saisis l'heure, regarde le
  rétroplanning, imprime la carte.
- **En avance** : prépare deux repas, range l'un au congélateur ; la veille du repas, la cloche doit dire « Décongeler ».
- **Téléphone** : par l'adresse https, active les notifications et envoie un essai (sur iPhone : écran d'accueil d'abord).
