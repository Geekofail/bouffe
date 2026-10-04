# Lot 15 — Foyer

**Objectif** : planning à deux, préférences respectées.

**Statut** : ✅ développé et vérifié en local (562 tests passés sous SQLite **et** MariaDB 10.11 ; parcours navigateur 1440 / 950 px et iPhone 390 px, mode clair et mode sombre) — ⏳ à valider sur ton poste.

Spécification : [06-version-2.md](../06-version-2.md), module 18 (18.1 à 18.5) et 14.7.

## Mise à jour depuis le lot 14

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan migrate      # contraintes du foyer, qui cuisine, réactions, rôles
php artisan config:clear
php artisan view:clear
php artisan test         # 562 tests
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 18.1 | **Préférences du foyer** | Paramètres → Foyer : allergies, « n'aime pas » et régimes de chaque membre, avec note. Les alertes sont **exactement celles des invités** (rouge pour une allergie, orange sinon), sur le planning, dans le choix d'un repas, à l'application d'une semaine type et dans le remplissage automatique | ✅ |
| 14.7 / 18.3 | **Qui cuisine** | Dans le détail d'un repas : personne, une personne du foyer, ou « ensemble » ; pastille sur la case du planning ; bouton **Mes repas** qui met les autres en retrait ; compteur de la semaine (« vous cuisinez 3 repas ») | ✅ |
| 18.2 | **Envies partagées** | Une envie notée par quelqu'un d'autre apparaît dans la cloche : « Monique a une envie : lasagnes », avec un bouton « Vu » | ✅ |
| 18.4 | **Réactions** | Après « mangé » : **On a aimé** / **Bof**, une réaction par personne, un second clic l'annule. Visible sur la fiche recette (« 3 × on a aimé »), proposée dans la cloche pour les repas des 3 derniers jours, et prise en compte par le remplissage automatique | ✅ |
| 18.5 | **Membres et rôles** | Créer un compte depuis l'écran Foyer (prénom, e-mail, mot de passe, rôle) ou en ligne de commande (`php artisan bouffe:user --role=viewer`) ; rôle **Complet** ou **Consultation et courses** | ✅ |
| 15.6 | Tests | +21 tests (contraintes du foyer et cumul avec les invités, absences, qui cuisine, réactions, envies partagées, rôles) — 562 au total | ✅ |

## Règles

### Contraintes du foyer (18.1)

- Même table de vérité que les invités : `GuestCompatibility` reçoit indifféremment un invité ou un membre du foyer.
- Une personne **absente** de la case (convives du lot 6) n'apporte pas ses contraintes à ce repas.
- Les contraintes du foyer et des invités **se cumulent** : un repas peut être signalé deux fois, une fois par personne.
- Le remplissage automatique (R15) écarte les recettes dangereuses (allergie) pour quelqu'un à table, foyer compris.

### Qui cuisine (14.7)

- Trois états : personne de désigné, une personne, ou « ensemble ».
- La pastille de la case porte l'initiale (ou « 2 » pour « ensemble ») ; le détail complet est dans l'infobulle.
- « Mes repas » estompe ce que quelqu'un d'autre cuisine plutôt que de le masquer : la semaine reste lisible d'un coup d'œil.

### Réactions (18.4)

- Possible seulement une fois le repas marqué **mangé**, et seulement sur un repas avec recette.
- Une réaction sur des **restes** compte pour la recette d'origine.
- Effet sur le remplissage : `+8` si le bilan du foyer est positif, `−25` s'il est négatif (la recette reste proposable, elle passe juste après les autres).
- C'est volontairement plus léger que la note sur 5 du lot 2 : on réagit sur le vif, la note reste pour un avis réfléchi.

### Rôles (18.5)

| Rôle | Ce qu'il peut faire |
|---|---|
| **Complet** | Tout : recettes, planning, stock, réglages, membres |
| **Consultation et courses** | Voir tout ; utiliser la liste de courses (cocher, ajouter un article) ; noter une envie ; réagir à un repas |

- Deux garde-fous : on ne peut pas retirer ses propres droits, et il doit toujours rester **au moins un compte complet**.
- Les pages d'édition (nouvelle recette, import, remplissage, semaines types, inventaire, rangement des courses, réglages) redirigent un compte en consultation vers l'accueil avec un message ; les boutons correspondants ne lui sont pas affichés ; les actions sensibles du planning et du stock répondent par un message plutôt que d'agir.
- **Ce n'est pas une barrière de sécurité** : c'est un garde-fou entre personnes de confiance, qui évite les fausses manœuvres. Une personne mal intentionnée ayant le mot de passe n'en serait pas empêchée.

## Routes

| URL | Nom | Composant |
|---|---|---|
| `/parametres/foyer` | `settings.household` | `Settings\Household` |
| `/planning?mes_repas=true` | `planner.week` | filtre « Mes repas » |

## Modèle de données

| Table / colonne | Contenu |
|---|---|
| `household_restrictions` (user_id, type, ingredient_id, tag_id, note) | Contraintes alimentaires du foyer, même forme que `guest_restrictions` |
| `meal_reactions` (planned_meal_id, user_id, value, comment) | 👍 / 👎, une par personne et par repas |
| `planned_meals.cook_user_id` + `cook_together` | Qui cuisine |
| `users.role` | `full` ou `viewer` |

## Services

| Classe | Rôle |
|---|---|
| `Services\Planning\HouseholdService` | Qui vit ici, qui est présent à une case, contraintes ; `eaters()` réunit foyer et invités |
| `Services\Planning\MealFeedback` | Réactions : `toggle()`, `forRecipe()`, `balances()`, `awaiting()` |
| `Http\Middleware\EnsureCanEdit` (`can.edit`) | Pages réservées aux comptes complets |
| `Support\Concerns\RequiresFullAccess` | Garde-fou des actions Livewire (`allowedToEdit()`) |

## Choix faits pendant le lot

- **Une seule mécanique d'alerte** pour le foyer et les invités, plutôt qu'un second calcul : le document 06 laissait le choix entre une table commune et une table dédiée ; une table dédiée avec la même forme donne le même résultat sans compliquer les invités.
- **Les réactions restent séparées de la note sur 5** (le document disait « alimente la note moyenne ») : mélanger un 👍 rapide et une note réfléchie aurait faussé les deux. Le remplissage utilise les deux, chacune avec son poids.
- **Un compte en consultation peut réagir et noter une envie** : ce sont ses goûts, pas une modification des données communes.
- **Le rôle est un garde-fou, pas une sécurité** : c'est écrit dans l'application même, sous la liste des membres.
- `MealPicker::guestInfo()` passait par une collection non-Eloquent : corrigé au passage (`loadMissing` échouait dès qu'une contrainte de foyer existait sans invité).

## Prochain lot

**Lot 16 — En ligne et hors-ligne** : accès sécurisé (question Q16 encore ouverte), PWA, liste de courses hors-ligne, mode magasin, sauvegarde externe.
