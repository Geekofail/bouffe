# Lot 35 — Écran de cuisine et bilan

**Objectif** : la tablette de cuisine ; les chiffres de l'année.

**Statut** : ✅ développé et vérifié en local — ⏳ à valider sur ton poste.

- **980 tests** passent sous SQLite, MariaDB 10.11 et MySQL 8.0 (+8).
- **60 vérifications dans le navigateur** passent (+10) : les trois nouvelles pages sur iPhone et sur
  ordinateur, les minuteurs de l'écran de cuisine et son passage en sombre le soir.
- **Aucune migration** : tout est calculé à partir du planning et du stock existants.

Spécification : [08-version-4.md](../08-version-4.md), module 35.

## Mise à jour depuis le lot 33

Avant que les fichiers arrivent, crée la branche du lot :

```powershell
cd C:\wamp64\www\Bouffe
git switch main
git pull
git switch -c lot-35-cuisine-bilan
```

Puis, une fois les fichiers déposés, ferme ton éditeur :

```powershell
cd C:\wamp64\www\Bouffe\src
php artisan bouffe:deploy
```

Quand le lot te convient :

```powershell
cd C:\wamp64\www\Bouffe
git add -A
git commit -m "Lot 35 — écran de cuisine et bilan"
git push -u origin lot-35-cuisine-bilan
```

## Sous-fonctionnalités

| # | Sous-fonctionnalité | Contenu | Statut |
|---|---|---|---|
| 35.1 | **Écran de cuisine** | `/cuisine` (Accueil › **Écran de cuisine** sur tablette et ordinateur, ou Planning › Plus). Plein écran, sans menu : grande horloge ; **Aujourd'hui** (photo, portions, durée, bouton **Cuisiner** qui ouvre le mode cuisine) et **Demain** ; **Minuteurs** : ceux lancés depuis le mode cuisine sur la même tablette, plus des minuteurs rapides +5, +10, +15, +30 min ; **À préparer** (rappels du jour et du lendemain, en retard compris, bouton **Fait**) ; **Courses** : ce qui reste à acheter dans la liste en cours et un champ « Il manque… » qui ajoute à la liste (ou en crée une). Texte et boutons grands ; écran maintenu allumé ; mis à jour tout seul chaque minute ; **sombre de 19 h à 7 h**, quel que soit le thème choisi | ✅ |
| 35.2 | **Statistiques de planning** | Planning › Plus › **Statistiques** (`/planning/statistiques`), sur le dernier mois, 3, 6 ou 12 mois : plats mangés et repas ; **planning suivi** (mangés sur planifiés, avec les « pas faits » et ceux à clôturer) ; recettes différentes et découvertes ; plats **sans viande ni poisson** par semaine (moyenne et graphique des 12 dernières semaines) ; part de plats **de saison** au mois où ils ont été mangés ; **restes finis** ; les 10 recettes **les plus cuisinées** | ✅ |
| 35.3 | **L'année en cuisine** | `/planning/annee` (depuis les statistiques ; sur l'accueil, un bandeau en décembre et jusqu'au 15 janvier) : plats, repas, recettes différentes, découvertes, les 5 classiques, mois par mois, habitudes (végétarien, saison, restes, planning suivi), **ce qui a été jeté** en euros d'après les prix relevés et la comparaison avec **la même période** de l'année précédente. Chaque chiffre dit sur quoi il repose. Boutons **Partager** (le texte des chiffres, par le menu de partage du téléphone, ou copié) et **Imprimer**. Les années précédentes restent consultables | ✅ |
| — | Tests | +8 tests Pest ; +10 vérifications dans le navigateur | ✅ |

## Choix et limites

- **Tablette** (Q50, réponse par défaut) : l'écran est prévu pour une tablette de 10 pouces en
  paysage (deux colonnes) et reste utilisable sur un téléphone posé (une colonne).
- **Minuteurs** : ils restent dans le navigateur, comme en mode cuisine (lot 12). Un minuteur lancé
  depuis un téléphone n'apparaît donc pas sur la tablette ; ceux lancés en mode cuisine **sur la
  tablette** s'y retrouvent. Un minuteur terminé depuis plus d'une heure est oublié.
- **Écran allumé** : seulement en HTTPS (comme le mode cuisine) ; sur le Wi-Fi de la maison en
  `http://`, la page le signale. Pour une tablette posée en permanence, régler aussi sa mise en
  veille dans ses propres réglages.
- **Mise à jour chaque minute** : si la session expire (plusieurs jours sans « Se souvenir de moi »),
  la page demande de se reconnecter.
- **« Sombre le soir »** : d'après l'heure de la tablette ; hors de 19 h – 7 h, le thème choisi
  (clair, sombre, automatique) s'applique.
- **Ce qui compte comme…**
  - *plat* : une recette, des restes ou un repas libre dans une case ; *repas* : un créneau d'un
    jour (entrée, plat et dessert du même soir = un repas) ;
  - *mangé* : coché « mangé » (à la main ou par la clôture automatique) ; un repas passé ni mangé ni
    « pas fait » est **à clôturer**, et affiché comme tel ; les jours à venir ne comptent pas ;
  - *les plus cuisinées* : les restes ne comptent pas (ce n'est pas cuisiner une deuxième fois) ;
  - *sans viande ni poisson* : plats principaux seulement, avec le même classement que l'équilibre
    de la semaine du planning (les trois ingrédients les plus lourds) ;
  - *de saison* : parmi les plats qui ont au moins un fruit ou légume de saison connue ; les pâtes
    au beurre ne comptent ni pour ni contre ;
  - *restes finis* : repas de restes mangés sur ceux clôturés, et plats préparés du stock finis
    plutôt que jetés ; un geste annulé ne compte pas ;
  - *jeté* : articles marqués « jeté » dans le stock ; les euros ne portent que sur ceux qui ont un
    prix relevé (le nombre est indiqué). La comparaison avec l'an dernier n'apparaît que si les deux
    périodes ont des prix.
- **Partager** : ni lien public ni envoi — le texte (« Notre année 2026 en cuisine : 212 plats
  mangés, 13 recettes différentes… ») part par le menu de partage du téléphone ; il ne contient
  aucun prénom.

## Fichiers

| Fichier | Rôle |
|---|---|
| `app/Services/Planning/PlanningStats.php` | Tous les calculs (période, année, restes, gaspillage) |
| `app/Livewire/Kitchen/Screen.php` + `views/livewire/kitchen/screen.blade.php`, `day.blade.php` | 35.1 |
| `resources/views/layouts/kitchen.blade.php` | Mise en page plein écran de la tablette |
| `app/Livewire/Planner/Statistics.php` + vue | 35.2 |
| `app/Livewire/Planner/YearInReview.php` + vue | 35.3 |
| `resources/views/components/stats/tile.blade.php`, `bars.blade.php` | Chiffre clé avec sa base ; barres |
| `tests/Feature/Planning/KitchenAndStatsTest.php`, `tests/Browser/kitchen.spec.js` | Tests |

Fichiers modifiés : `resources/js/app.js` (minuteurs de la tablette, soir), `routes/web.php`,
l'accueil (lien « Écran de cuisine », bandeau de décembre), l'en-tête du planning (Statistiques,
L'année en cuisine, Écran de cuisine), `tests/Browser/pages.spec.js` (trois pages de plus).

## À vérifier sur ton poste

- Sur une tablette (ou l'ordinateur) : **Accueil › Écran de cuisine**. Lancer un minuteur +5 ;
  ouvrir **Cuisiner**, lancer un minuteur d'étape, revenir : il est là aussi.
- Taper « lait » dans « Il manque… », puis vérifier la liste de courses.
- **Planning › Plus › Statistiques** : passer de 3 à 12 mois.
- **L'année en cuisine** : **Partager** sur le téléphone, **Imprimer** sur l'ordinateur.

Les captures du lot ont été faites sur une base d'essai dont l'historique (un an de repas) a été
**inventé** pour l'occasion.
