<?php

namespace App\Livewire\Settings;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\RecurringItem;
use App\Models\Tag;
use App\Models\Unit;
use App\Services\Backup\BackupManager;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Paramètres')]
class Index extends Component
{
    public function render(BackupManager $manager)
    {
        $backups = $manager->all();
        $latest = $manager->latest();

        return view('livewire.settings.index', [
            'sections' => [
                ['route' => 'settings.ingredients', 'icon' => 'list', 'title' => 'Ingrédients', 'count' => Ingredient::count(),
                    'text' => 'Rayon, unité par défaut, poids moyen, produits de base.'],
                ['route' => 'settings.aisles', 'icon' => 'cart', 'title' => 'Rayons', 'count' => Aisle::count(),
                    'text' => 'L\'ordre de votre magasin, pour trier la liste de courses.'],
                ['route' => 'settings.stores', 'icon' => 'store', 'title' => 'Magasins', 'count' => \App\Models\Store::count(),
                    'text' => 'L\'ordre des rayons de chaque magasin, suivi par la liste de courses.'],
                ['route' => 'settings.seasons', 'icon' => 'leaf', 'title' => 'Saisons', 'count' => \App\Models\Ingredient::whereNotNull('season_months')->count(),
                    'text' => 'Les mois de saison des fruits et légumes, utilisés par le planning.'],
                ['route' => 'settings.nutrition', 'icon' => 'nutrition', 'title' => 'Nutrition', 'count' => \App\Models\NutritionFood::count(),
                    'text' => 'Table Ciqual importée et correspondance avec vos ingrédients.'],
                ['route' => 'settings.budget', 'icon' => 'euro', 'title' => 'Budget', 'count' => null,
                    'text' => 'Postes, budgets mensuels, début de période et dépenses récurrentes.'],
                ['route' => 'settings.receipts', 'icon' => 'receipt', 'title' => 'Tickets de caisse', 'count' => \App\Models\ReceiptLabelMapping::count() ?: null,
                    'text' => 'Service de lecture, clé, plafond mensuel, conservation des photos, libellés appris.'],
                ['route' => 'settings.assistant', 'icon' => 'sparkles', 'title' => 'Assistant culinaire', 'count' => null,
                    'text' => 'Activer, plafond mensuel en euros, consommation. Rien n\'est envoyé sans que vous le demandiez.'],
                ['route' => 'settings.units', 'icon' => 'scale', 'title' => 'Unités', 'count' => Unit::count(),
                    'text' => 'g, kg, cuillères, pièces… et leurs conversions.'],
                ['route' => 'settings.tags', 'icon' => 'tag', 'title' => 'Catégories', 'count' => Tag::count(),
                    'text' => 'Pour classer et filtrer les recettes.'],
                ['route' => 'settings.slots', 'icon' => 'clock', 'title' => 'Créneaux', 'count' => MealSlot::active()->count(),
                    'text' => 'Les repas affichés dans le planning (actifs).'],
                ['route' => 'settings.recurring', 'icon' => 'cart', 'title' => 'Articles récurrents', 'count' => RecurringItem::active()->count(),
                    'text' => 'Ajoutés à chaque nouvelle liste : café, lait, pain…'],
                ['route' => 'settings.locations', 'icon' => 'pantry', 'title' => 'Emplacements', 'count' => \App\Models\StorageLocation::count(),
                    'text' => 'Réfrigérateur, congélateur, placard… : les onglets du stock.'],
                ['route' => 'settings.stock', 'icon' => 'warning', 'title' => 'Alertes stock', 'count' => null,
                    'text' => 'Délais « à consommer bientôt », badge du menu, bandeau du planning.'],
                ['route' => 'settings.backups', 'icon' => 'archive', 'title' => 'Sauvegardes', 'count' => $backups->count(),
                    'text' => $latest ? 'Dernière : '.$latest->createdAt->locale('fr')->diffForHumans().'.' : 'Aucune sauvegarde pour l\'instant.'],
                ['route' => 'settings.phone', 'icon' => 'phone', 'title' => 'Accès téléphone', 'count' => null,
                    'text' => 'Ouvrir Bouffe sur les téléphones, sur le Wi-Fi de la maison.'],
                ['route' => 'settings.display', 'icon' => 'squares', 'title' => 'Affichage', 'count' => null,
                    'text' => 'Thème clair ou sombre, barre du bas du téléphone, blocs de l\'accueil.'],
                ['route' => 'settings.notifications', 'icon' => 'bell', 'title' => 'Notifications', 'count' => null,
                    'text' => 'Rappels sur le téléphone, récapitulatif du dimanche par e-mail, tâche planifiée.'],
                ['route' => 'settings.ingredient-duplicates', 'icon' => 'merge', 'title' => 'Doublons d\'ingrédients', 'count' => null,
                    'text' => 'Fusionner deux ingrédients en double ; l\'ancien nom reste reconnu.'],
                ['route' => 'settings.diagnostic', 'icon' => 'info', 'title' => 'Diagnostic et À propos', 'count' => null,
                    'text' => 'L\'état de l\'installation, l\'export de vos données, la commande de mise à jour.'],
                // Lot 25 (module 27).
                ['route' => 'settings.online', 'icon' => 'share', 'title' => 'Mise en ligne', 'count' => null,
                    'text' => 'Tâches planifiées, surveillance, sécurité et sauvegardes de l\'installation en ligne.'],
                // Lot 30 (30.2).
                ['route' => 'journal', 'icon' => 'clock', 'title' => 'Journal du foyer', 'count' => null,
                    'text' => 'Qui a fait quoi ces 30 derniers jours : repas, courses, stock, envies.'],
                ['route' => 'account.show', 'icon' => 'user', 'title' => 'Mon compte', 'count' => null,
                    'text' => 'Mot de passe, double authentification, appareils connectés.'],
                ['route' => 'privacy', 'icon' => 'lock', 'title' => 'Vos données', 'count' => null,
                    'text' => 'Ce qui est enregistré, où, qui y a accès, comment l\'exporter ou l\'effacer.'],
            ],
            'version' => config('bouffe.version'),
            'isAdmin' => auth()->user()?->isAdmin() ?? false,
        ]);
    }
}
