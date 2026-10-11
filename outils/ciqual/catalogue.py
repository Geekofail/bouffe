# Génère src/database/data/catalogue-ciqual.csv à partir de la table Ciqual 2025 (Anses).
#
#   python3 outils/ciqual/catalogue.py src/database/data/ciqual-2025.csv src/database/data/catalogue-ciqual.csv
#
# Le catalogue = les ~150 ingrédients historiques de Bouffe (IngredientSeeder), chacun avec son
# aliment Ciqual choisi à la main, + des ingrédients tirés de Ciqual : un par aliment « tel qu'on
# l'achète » (cru, sec, en conserve…), avec un nom court et un rayon. Les choix à la main sont en
# tête de fichier (CHOIX, AJOUTS, RENOMMAGES, RETRAITS). Le script est déterministe.
import csv, json, re, sys, unicodedata, collections, os

SRC = sys.argv[1] if len(sys.argv) > 1 else 'src/database/data/ciqual-2025.csv'
DST = sys.argv[2] if len(sys.argv) > 2 else 'src/database/data/catalogue-ciqual.csv'
HERE = os.path.dirname(os.path.abspath(__file__))
EXISTING = json.load(open(os.path.join(HERE, 'ingredients-historiques.json'), encoding='utf-8'))

rows = list(csv.reader(open(SRC, encoding='utf-8-sig'), delimiter=';'))[1:]
BY_NAME = {r[7]: r for r in rows}
BY_CODE = {r[6]: r for r in rows}


def C(ref):
    """Code Ciqual d'après son code ou son nom exact."""
    if ref in BY_CODE:
        return ref
    return BY_NAME[ref][6]


def norm(s):  # = App\Support\NameNormalizer::normalize
    s = s.replace('œ', 'oe').replace('Œ', 'oe').replace('æ', 'ae').replace('Æ', 'ae')
    s = unicodedata.normalize('NFKD', s).encode('ascii', 'ignore').decode().lower()
    s = re.sub(r'[^a-z0-9]+', ' ', s).strip()
    return ' '.join(w[:-1] if len(w) > 3 and w[-1] in 'sx' else w for w in s.split())


# --- 1. Les ingrédients historiques : l'aliment Ciqual choisi pour chacun ----------------------
CHOIX = {
    'Ail': 'Ail, cru', 'Oignon': 'Oignon, cru', 'Oignon rouge': 'Oignon rouge, cru', 'Échalote': 'Échalote, crue',
    'Tomate': '20385', 'Tomate cerise': 'Tomate cerise, crue', 'Carotte': 'Carotte, crue',
    'Pomme de terre': 'Pomme de terre, sans peau, crue', 'Patate douce': 'Patate douce, crue',
    'Courgette': 'Courgette, chair et peau, crue', 'Aubergine': 'Aubergine, crue', 'Poivron rouge': 'Poivron rouge, cru',
    'Poivron vert': 'Poivron vert, cru', 'Poireau': 'Poireau, cru', 'Céleri branche': 'Céleri branche, cru',
    'Champignon de Paris': 'Champignon de Paris ou champignon de couche, cru', 'Brocoli': 'Brocoli, cru',
    'Chou-fleur': 'Chou-fleur, cru', 'Épinards frais': '20059', 'Salade verte': 'Salade verte, crue, sans assaisonnement',
    'Concombre': 'Concombre, chair et peau, cru', 'Avocat': 'Avocat, chair sans peau, sans noyau, cru',
    'Haricots verts': '20061', 'Petits pois frais': 'Petits pois, crus', 'Butternut': 'Courge butternut (doubeurre), chair sans peau, crue',
    'Potiron': 'Potiron, cru', 'Navet': 'Navet, pelé, cru', 'Betterave cuite': '20003', 'Radis': '20045',
    'Fenouil': 'Fenouil, cru', 'Gingembre frais': '11074', 'Citron': '13009', 'Citron vert': '13067',
    'Orange': 'Orange, chair sans peau, sans pépins, crue', 'Pomme': 'Pomme, chair et peau, crue',
    'Poire': 'Poire, chair et peau, crue', 'Banane': 'Banane, chair sans peau, crue', 'Fraise': 'Fraise, crue',
    'Framboise': 'Framboise, crue', 'Persil': '11014', 'Coriandre': '11094', 'Ciboulette': '11003', 'Basilic': 'Basilic, frais',
    'Menthe': '11027', 'Thym frais': '11070', 'Romarin frais': '11068',
    'Baguette': '7007', 'Pain de mie': '7200', 'Pain complet': '7110', 'Pain burger': '7259', 'Tortilla': '7815',
    'Blanc de poulet': '36017', 'Cuisse de poulet': 'Poulet, cuisse, viande crue', 'Poulet entier': '36016',
    'Bœuf haché': '6254', 'Steak haché': '6254', 'Bœuf à braiser': '6231', 'Escalope de dinde': 'Dinde, escalope crue',
    'Rôti de porc': '28300', 'Côte de porc': 'Porc, côte crue', 'Saucisse': '30110', 'Agneau (épaule)': 'Agneau, épaule crue',
    'Pavé de saumon': '26036', 'Filet de cabillaud': '26043', 'Crevette': '10021', 'Moules': '10014',
    'Saumon fumé': '26037', 'Lardons': '28720', 'Jambon blanc': '28900', 'Jambon cru': 'Jambon cru', 'Chorizo': 'Chorizo',
    'Pâte feuilletée': 'Pâte feuilletée, matière grasse végétale, crue', 'Pâte brisée': 'Pâte brisée, pur beurre, préemballée, crue',
    'Pâte à pizza': 'Pâte à pizza, préemballée, crue', 'Gnocchis': '26264', 'Tofu': '20904', 'Œuf': '22000',
    'Lait demi-écrémé': '19041', 'Beurre': '16400', 'Crème fraîche épaisse': '19410', 'Crème liquide': '19417',
    'Yaourt nature': 'Yaourt ou lait fermenté, nature', 'Fromage blanc': 'Fromage blanc, nature, 2-3% MG',
    'Lait de coco': '18041', 'Emmental râpé': 'Emmental ou emmenthal', 'Parmesan': 'Parmesan',
    'Mozzarella': 'Mozzarella au lait de vache', 'Feta': 'Feta, au lait de brebis 70% minimum et lait de chèvre 30% maximum',
    'Chèvre frais': '12805', 'Comté': 'Comté', 'Reblochon': 'Reblochon', 'Ricotta': 'Ricotta',
    'Pâtes': '9810', 'Spaghetti': '9810', 'Lasagnes (feuilles)': '9810', 'Riz basmati': '9119', 'Riz arborio': '9100',
    'Semoule': '9610', 'Quinoa': 'Quinoa, cru', 'Lentilles corail': 'Lentille corail, sèche',
    'Lentilles vertes': 'Lentille verte, sèche', 'Pois chiches secs': 'Pois chiche, sec', 'Farine': '9436',
    'Chapelure': 'Chapelure', 'Bouillon de volaille': 'Bouillon de volaille, déshydraté',
    'Bouillon de légumes': 'Bouillon de légumes, déshydraté', "Huile d'olive": '17270', 'Huile de tournesol': 'Huile de tournesol',
    'Vinaigre balsamique': '11091', 'Vinaigre de vin': '11220', 'Sucre': '31016', 'Sucre roux': '31017',
    'Sucre vanillé': '31044', 'Levure chimique': '11046', 'Chocolat noir': '31085', 'Miel': 'Miel',
    'Confiture': 'Confiture, autres fruits ou mélange de fruits (extra ou classique)', "Flocons d'avoine": '32140',
    "Poudre d'amande": '15041', 'Cacao en poudre': '18100', 'Tomates concassées': '20169', 'Concentré de tomate': '20068',
    'Coulis de tomate': '20260', 'Pois chiches en boîte': '20532', 'Haricots rouges en boîte': '20524',
    'Maïs en boîte': '20066', 'Thon au naturel': '26039', 'Olives noires': '13032', 'Cornichons': 'Cornichon, aigre-doux',
    'Câpres': 'Câpres, au vinaigre', 'Sel': '11058', 'Poivre': '11015', 'Moutarde': '11013',
    'Mayonnaise': 'Mayonnaise (70% MG min.), préemballée', 'Ketchup': 'Ketchup, préemballé', 'Sauce soja': '11104',
    'Paprika': 'Paprika, poudre', 'Cumin': 'Cumin, graine', 'Curry en poudre': '11005', 'Curcuma': 'Curcuma, poudre',
    "Piment d'Espelette": '11088', 'Herbes de Provence': '11060', 'Thym séché': '11038', 'Laurier': 'Laurier, feuille',
    'Muscade': 'Noix de muscade', 'Cannelle': 'Cannelle, poudre', 'Vanille (gousse)': 'Vanille, gousse',
    'Petits pois surgelés': '20084', 'Épinards surgelés': '20083', 'Poêlée de légumes surgelée': '20262',
    'Frites surgelées': '4044', 'Vin blanc': '5215', 'Vin rouge': '5214', 'Bière': '5001',
}

# Poids d'une unité pour les historiques qui n'en avaient pas (botte, brin, pot, boîte…), en g.
POIDS = {'Radis': 250, 'Persil': 50, 'Coriandre': 50, 'Ciboulette': 30, 'Basilic': 30, 'Menthe': 30,
         'Thym frais': 1, 'Romarin frais': 1, 'Confiture': 370, 'Tomates concassées': 400,
         'Pois chiches en boîte': 265, 'Haricots rouges en boîte': 250, 'Maïs en boîte': 285,
         'Thon au naturel': 140, 'Cornichons': 200, 'Laurier': 0.2, 'Vanille (gousse)': 3}

# --- 2. Ajouts à la main : [nom, aliment Ciqual, rayon, unité, pluriel] ---------------------------
AJOUTS = [
    ['Blanc d\'œuf', '22001', 'dairy', 'piece', 'blancs d\'œufs'], ['Jaune d\'œuf', '22002', 'dairy', 'piece', 'jaunes d\'œufs'],
    ['Zeste de citron', '13125', 'fruits', 'g', None], ['Cerise', '13008', 'fruits', 'g', 'cerises'],
    ['Melon', 'Melon cantaloup (par ex.: Charentais), chair sans peau, sans pépins, cru', 'fruits', 'piece', 'melons'],
    ['Cresson', 'Cresson de fontaine, cru', 'fruits', 'g', None],
    ['Tomates pelées', '20048', 'cans', 'g', None], ['Purée de tomate', '20170', 'cans', 'g', None],
    ['Tomate séchée', '20189', 'cans', 'g', 'tomates séchées'],
    ['Haricots blancs en boîte', '20511', 'cans', 'g', None], ['Flageolets en boîte', '20508', 'cans', 'g', None],
    ['Lentilles en boîte', '20510', 'cans', 'g', None], ['Champignons de Paris en boîte', '20079', 'cans', 'g', None],
    ['Haricots verts en boîte', '20062', 'cans', 'g', None], ['Petits pois en boîte', '20036', 'cans', 'g', None],
    ['Cœurs d\'artichaut', '20155', 'cans', 'g', None], ['Poivron rouge en bocal', '20275', 'cans', 'g', None],
    ['Sardines à l\'huile', '26034', 'cans', 'g', None], ['Sardines à l\'huile d\'olive', '26040', 'cans', 'g', None],
    ['Sardines à la tomate', '26035', 'cans', 'g', None], ['Thon à l\'huile', '26245', 'cans', 'g', None],
    ['Maquereau au vin blanc', '26097', 'cans', 'g', None], ['Maquereau à la tomate', '26086', 'cans', 'g', None],
    ['Maquereau à la moutarde', '26096', 'cans', 'g', None], ['Saumon en boîte', '26119', 'cans', 'g', None],
    ['Miettes de crabe', '10005', 'cans', 'g', None],
    ['Haricots verts surgelés', '20070', 'frozen', 'g', None], ['Brocoli surgelé', '20204', 'frozen', 'g', None],
    ['Chou-fleur surgelé', '20082', 'frozen', 'g', None], ['Légumes surgelés', '20101', 'frozen', 'g', None],
    ['Julienne de légumes surgelée', '20265', 'frozen', 'g', None], ['Champignons surgelés', '20228', 'frozen', 'g', None],
    ['Oignon surgelé', '20235', 'frozen', 'g', None], ['Poêlée asiatique surgelée', '20273', 'frozen', 'g', None],
    ['Poêlée méridionale surgelée', '20274', 'frozen', 'g', None], ['Fèves surgelées', '20541', 'frozen', 'g', None],
    ['Framboises surgelées', '13136', 'frozen', 'g', None], ['Myrtilles surgelées', '13132', 'frozen', 'g', None],
    ['Crevettes surgelées', '10038', 'frozen', 'g', None], ['Filet de merlu surgelé', '26048', 'frozen', 'g', None],
    ['Glace', '39527', 'frozen', 'g', None],
    ['Crevettes cuites', '10086', 'fish', 'g', None], ['Crevettes grises', '10089', 'fish', 'g', None],
    ['Saumon sauvage', '26161', 'fish', 'g', None],
    ['Persil séché', '11024', 'spices', 'cc', None], ['Romarin séché', '11036', 'spices', 'cc', None],
    ['Ciboulette séchée', '11095', 'spices', 'cc', None], ['Menthe séchée', '11029', 'spices', 'cc', None],
    ['Ail en poudre', '11023', 'spices', 'cc', None], ['Gingembre en poudre', '11006', 'spices', 'cc', None],
    ['Herbes fraîches', '11080', 'fruits', 'g', None],
    ['Fleur de sel', 'Fleur de sel, non iodée, non fluorée', 'spices', 'pincee', None],
    ['Gros sel', '11083', 'spices', 'cc', None],
    ['Farine T45', '9440', 'savory', 'g', None], ['Farine complète', '9415', 'savory', 'g', None],
    ['Farine à levure incorporée', '9437', 'savory', 'g', None], ['Farine T65', '9435', 'savory', 'g', None],
    ['Sucre glace', '31016', 'sweet', 'g', None], ['Cassonade', '31017', 'sweet', 'g', None],
    ['Riz blanc', '9100', 'savory', 'g', None], ['Riz rond', '9100', 'savory', 'g', None], ['Riz thaï', '9119', 'savory', 'g', None],
    ['Tagliatelles', '9821', 'savory', 'g', None], ['Tagliatelles fraîches', '9815', 'savory', 'g', None],
    ['Penne', '9810', 'savory', 'g', None], ['Macaroni', '9810', 'savory', 'g', None], ['Coquillettes', '9810', 'savory', 'g', None],
    ['Fusilli', '9810', 'savory', 'g', None], ['Farfalle', '9810', 'savory', 'g', None],
    ['Pâtes complètes', '9870', 'savory', 'g', None], ['Nouilles aux œufs', '9863', 'savory', 'g', None],
    ['Pâtes sans gluten', '9874', 'savory', 'g', None],
    ['Lait entier', '19023', 'dairy', 'ml', None], ['Lait écrémé', '19050', 'dairy', 'ml', None],
    ['Beurre demi-sel', '16402', 'dairy', 'g', None], ['Beurre salé', '16403', 'dairy', 'g', None],
    ['Beurre allégé', '16415', 'dairy', 'g', None],
    ['Crème fraîche légère', '19431', 'dairy', 'g', None], ['Crème liquide légère', '19436', 'dairy', 'ml', None],
    ['Crème semi-épaisse', '19415', 'dairy', 'g', None],
    ['Chocolat noir à croquer', '31005', 'sweet', 'g', None], ['Chocolat noir 70 %', '31074', 'sweet', 'g', None],
    ['Amandes', '15000', 'savory', 'g', None], ['Amandes effilées', '15041', 'savory', 'g', None],
    ['Noix de coco râpée', '15007', 'savory', 'g', None], ['Noix de coco fraîche', '15006', 'fruits', 'g', None],
    ['Viande hachée de bœuf', '6254', 'butcher', 'g', None], ['Bœuf bourguignon', '6231', 'butcher', 'g', None],
    ['Steak haché 5 %', '6250', 'butcher', 'piece', 'steaks hachés 5 %'],
    ['Filet de poulet', '36017', 'butcher', 'g', 'filets de poulet'],
    ['Rôti de veau', '6550', 'butcher', 'g', None], ['Filet mignon de porc', '28480', 'butcher', 'g', None],
    ['Jambon à rôtir', '28700', 'butcher', 'g', None],
    ['Jambon blanc découenné dégraissé', '28906', 'deli', 'tranche', None], ['Jambon cuit fumé', '28803', 'deli', 'tranche', None],
    ['Saucisse de Strasbourg', '30742', 'deli', 'piece', 'saucisses de Strasbourg'],
    ['Lardons nature', '28501', 'deli', 'g', None],
    ['Olives vertes', '13033', 'spices', 'g', None], ['Olives farcies', '13147', 'spices', 'g', None],
    ['Olives noires à la grecque', '13131', 'spices', 'g', None],
    ['Vin', '5210', 'drinks', 'ml', None], ['Vin cuisiné', '5217', 'drinks', 'ml', None],
    ['Sauce soja sucrée', '11216', 'spices', 'cs', None],
    ['Pain', '7001', 'bakery', 'g', None], ['Pain grillé', '7400', 'bakery', 'tranche', None],
    ['Pain de mie sans croûte', '7201', 'bakery', 'tranche', None], ['Tortilla de maïs', '7813', 'bakery', 'piece', 'tortillas de maïs'],
    ['Pain d\'épices', '23200', 'sweet', 'g', None],
    # Ingrédients de SubstitutionSeeder (remplacements courants, lot 40).
    ['Margarine', '16590', 'dairy', 'g', None], ['Fécule de maïs', '9510', 'savory', 'g', None],
    ['Farine sans gluten', 'Farine de riz', 'savory', 'g', None], ['Graines de lin moulues', '15034', 'savory', 'g', None],
    ['Boisson végétale', '18112', 'drinks', 'ml', None], ['Chèvre', '12805', 'cheese', 'g', None],
    ['Épinards en branches', '20060', 'cans', 'g', None], ['Jeunes pousses d\'épinard', '20270', 'fruits', 'g', None],
    ['Flocons de pomme de terre', '4022', 'savory', 'g', None],
    ['Pommes de terre sautées surgelées', '4043', 'frozen', 'g', None],
    ['Pommes de terre sarladaises surgelées', '4051', 'frozen', 'g', None],
]

# --- 3. Renommages de noms générés ---------------------------------------------------------------
RENOMMAGES = {
    'Ceriser': None, 'Lait à 1': 'Lait à 1,2 % MG', 'Pâtes': None, 'Huile': 'Huile de coco',
    'Huile d\'olive vierge extra': None, 'Mozzarella au lait de bufflonne': 'Mozzarella di bufala',
    'Mélange de fromages râpés': 'Fromage râpé', 'Fromage de chèvre frais': None,
    'Filet sans peau de poulet': None, 'Cuisse avec peau de canard': 'Cuisse de canard',
    'Steak de bœuf': 'Steak de bœuf', 'À bourguignon de bœuf': None, 'Dés': 'Dés de jambon',
    'Riz': 'Riz mélange de variétés', 'Biscuit': 'Biscuit apéritif soufflé', 'Chips': 'Chips à la crevette',
    'Courge graine': 'Graines de courge', 'Lin': 'Graines de lin', 'Chia': 'Graines de chia', 'Tournesol': 'Graines de tournesol',
    'Sésame': 'Graines de sésame', 'Pavot': 'Graines de pavot', 'Chanvre': 'Graines de chanvre', 'Soja': 'Soja en grains',
    'Courge': 'Courge', 'Lentille': None, 'Œuf': None, 'Barre': 'Barre chocolatée au lait',
    'Thé': 'Thé en feuilles', 'Café': 'Café moulu', 'Cacao': None, 'Crème': None, 'Lait': None, 'Glace': None,
    'Bière ""cœur de marché""': None, 'Lait fermenté': 'Yaourt à boire', 'Graine de couscous': 'Couscous (graine)',
    'Biscuit sec': 'Biscuits secs', 'Gelée': 'Gelée de fruits', 'Confiture': None, 'Nectar': None,
    'Fromage à pâte molle': 'Fromage triple crème', 'Spécialité végétale type fromage': 'Fromage végétal',
    'Cocktail': 'Cocktail sans alcool', 'Préparation aux fruits divers ingrédient)': None,
    'Bouchée/bonbon au chocolat fourrage gaufrettes': None, 'Fromage fondu': None,
    'Cœur': 'Cœur de porc', 'Foie': 'Foie d\'oie', 'Gésier': 'Gésier de poulet', 'Langue': 'Langue de veau',
    'Ris': 'Ris de veau', 'Rognon': 'Rognon de porc', 'Cervelle': None, 'Sang': None, 'Tripes': 'Tripes de bœuf',
    'Base de pizza': None, 'Matière grasse': 'Graisse végétale (type margarine)', 'Beurre': None,
    'Beurre à 80% MG': None, 'Beurre à 80% MG minimum': None, 'Beurre à 60-62% MG': None, 'Beurre à 39-41% MG': None,
    'Crème 30% MG': None, 'Crème 12 à 20% MG': None, 'Crème 12 à 25% MG': None,
    'Lait demi-écrémé': None, 'Lait écrémé': None, 'Lait entier': None,
    'Chocolat noir 40% de cacao et plus': None, 'Chocolat noir 50 % de cacao environ': None,
    'Chocolat noir 70 % de cacao environ': None, 'Sucre blanc': None, 'Sel blanc alimentaire': None,
    'Poivre noir': None, 'Riz thaï': None, 'Riz blanc': None, 'Farine de blé tendre': None, 'Fleur de sel': None,
    'Moule commune': None, 'Crevette': None, 'Tofu nature': None, 'Haricot vert': None, 'Petits pois': None,
    'Herbes de Provence': None, 'Ciboule': None, 'Ciboulette': None, 'Menthe': None, 'Cuisse de poulet': None,
    'Pâtes sèches': None, 'Pâtes fraîches': None, 'Lait de coco': None, 'Courge butternut': None, 'Radis rouge': None,
    'Olive noire': None, 'Olive verte': None, 'Cornichon': None, 'Lardon fumé': None, 'Lardon nature': None, 'Lardon': None,
    'Saucisse de Strasbourg': None, 'Curry': None, 'Noix de muscade': None, 'Vanille': None, 'Steak haché 15% MG de bœuf': None,
    'Escalope de dinde': None, 'Côte de porc': None, 'Épaule d\'agneau': None, 'Sauce soja': None,
    'Câpres': None, 'Ketchup': None, 'Mayonnaise': None, 'Paprika': None, 'Cumin': None, 'Curcuma': None, 'Laurier': None,
    'Cannelle': None, 'Concombre': None, 'Coriandre': None, 'Persil': None, 'Thym': None, 'Romarin': None,
    'Maïs doux': 'Maïs doux surgelé',  'Miel': None, 'Chapelure': None,
    'Bouillon de volaille': None, 'Bouillon de légumes': None, 'Huile de tournesol': None, 'Vinaigre balsamique': None,
    'Vinaigre de vin rouge': None, 'Sucre roux': None, 'Sucre vanillé': None, 'Levure chimique': None,
    'Flocons d\'avoine': None, 'Moutarde': None, 'Parmesan': None, 'Feta': None, 'Comté': None, 'Reblochon': None,
    'Quinoa': None, 'Lentille corail': None, 'Lentille verte': None, 'Pois chiche': None,
    'Semoule de blé dur': None, 'Fromage blanc': None, 'Yaourt': None, 'Jambon cru': None, 'Chorizo': None,
    'Pâte feuilletée': None, 'Pâte brisée': None, 'Pâte à pizza': None, 'Gnocchi à la pomme de terre': None,
    'Saumon fumé': None, 'Vin rouge': None, 'Vin blanc sec': None, 'Basilic': None, 'Gingembre': None,
}
# (None = l'entrée générée disparaît : un ingrédient historique ou un ajout la remplace.)

# --- 4. Retraits : trop rare, préparation et pas ingrédient, doublon ------------------------------
RETRAITS = set('''
Abricot pays|Ananas Victoria|Anone|Caïmite|Chadèque|Chips d'abricot pays|Chips de chair de giraumon|Dourian|Feijoa
|Fruit cru|Fruit du jacquier|Giraumon|Kaki de brousse|Mango bassignac|Mangue José|Mangue julie|Mangue moussache
|Mangue verte|Massissi|Moringa|Orange amère|Papaye Colombo|Papaye verte|Pomme cajou|Pomme cannelle|Pomme d'eau
|Pomme liane|Prune de cythère|Dessert de fruits|Spécialité de fruits|Spécialité de fruits divers|Raisin cru
|Tomate sans précision|Datte du désert|Farine de pulpe de patate douce|Pamplemousse chinois|Mandarine commune
|Mélange apéritif de fruits exotiques|Oronge vraie|Chips de pommes de terre nature|Salade de fruits|Sureau
|Légumes|Fondue de poireau|Purée de pomme de terre|Pomme Canada|Pomme Chantecler|Courge melonnette
|Cerise acérola|Pomelo|Kaki|Ramboutan|Longan|Tamarin|Fruit à pain|Pois d'Angole vert|Haricot de Lima|Gari|Frik
|Chips de maïs|Biscuit apéritif|Cacahuète enrobée d'un biscuit salé|Mélange apéritif de graines et fruits séchés
|Mélange apéritif de graines et raisins secs|Mélange de céréales et légumes secs|Pop-corn|Huile combinée|Huile de coton
|Huile de pavot|Huile de son de riz|Huile de noyaux d'abricot|Huile de germe de blé|Huile de cameline|Huile de palme
|Huile pour friture|Graisse de dinde|Graisse de poulet|Ascophylle noueux|Chlorelle|Fucus vésiculeux|Gracilaire
|Haricot de mer|Kombu breton|Kombu royal|Laitue de mer|Lichen de mer|Maërl|Wakamé atlantique|Ao-nori|Criste marine
|Luzerne|Lupin|Mil complet|Blé de Khorasan|Sorgho complet|Amarante|Spiruline
|Autruche|Bardière découennée de porc|Gorge découennée de porc|Hachage sans jarret de porc|Jambon sans jarret de porc
|Jambonneau arrière de porc|Poitrine cutter de porc|Maigre 80/20 de porc|Maigre 90/10 de porc|Poulet blanc
|Poulet éviscéré sans abats|Tête de mouton|Pied de mouton|Entrecôte de cheval|Faux-filet de cheval
|Tende de tranche de cheval|Steak de cheval|Côte découverte d'agneau|Côte filet d'agneau|Côte première d'agneau
|Épaule maigre d'agneau|Lapin de garenne|Haché à base de bœuf et soja|Poitrine de pintade|Cuisse de poule|Chapon
|Steak haché 15% MG de veau|Steak haché 20% MG de veau|Mouton|Cheval|Chevreau
|Andouille de Guéméné|Andouille de Vire|Andouillette de Troyes|Boudin antillais|Boudin blanc truffé|Cervelas à l'ail
|Cervelas obernois|Chair à saucisse crue|Chorizo supérieur|Confit de foie de porc|Confit de foie de volaille|Diot cru
|Farce porc et bœuf crue|Fines tranches végétales|Galantine|Jambon de volaille|Jambon en croûte|Merguez crue
|Mortadelle pistachée pur porc|Mousse de foie de porc supérieure|Museau de bœuf|Œuf au jambon en gelée
|Oreille de porc demi-sel|Pâté au jambon|Pâté au poivre vert|Pâté de gibier|Pavé au poivre|Pied de porc demi-sel
|Poitrine de porc en salaison|Quenelle au tofu|Quenelle de poisson en sauce|Quenelle de veau en sauce
|Quenelle de volaille en sauce|Rillettes de poulet|Rillettes de Tours|Rillettes du Mans|Rillettes pur oie
|Rillettes supérieures de porc|Rillettes traditionnelles de porc|Roulade de porc pistachée|Salami pur porc
|Salami type danois|Saucisse de jambon pur porc|Saucisse de langue à la pistache|Saucisse viennoise crue
|Saucisson de cheval type cervelas|Saucisson sec allégé en matière grasse|Saucisson sec de Lyon pur porc
|Saucisson sec pur porc|Spécialité végétale type pâté|Jambon persillé en gelée|Fromage de tête|Confit de foie
|Bar rayé|Bogue|Brème|Capelan|Carangue|Cardine franche|Chinchard gras|Chinchard maigre|Corb|Corégone lavaret
|Coulirou|Denté|Empereur|Esturgeon d’Europe occidentale|Flétan du Groënland|Grenadier|Grondin perlon|Hareng gras
|Hareng maigre|Hoki|Hoki de Nouvelle-Zélande|Joëls pour friture|Lingue bleue|Lompe|Lotte de rivière
|Loup de l'Atlantique|Loup tacheté|Maquereau espagnol|Merlu blanc du Cap|Orphie commune|Pecten d'Amérique
|Perche du Nil|Saumon argenté du Pacifique|Saupe|Sébaste du nord|Sole tropicale|Sabre|Tacaud|Thon listao
|Truite arc en ciel|Vivaneau|Mahi-mahi|Requin|Rouget-barbet de roche|Ormeau|Œufs de cabillaud|Œufs de saumon du Pacifique
|Moules à la sauce catalane|Carpaccio de saumon avec marinade|Pilchard|Accra de poisson|Beignet de crevette
|Terrine de fruits de mer|Rillettes de crabe|Grondin|Mulet|Baudroie rousse|Huître|Thon à la tomate
|Boulette d'Avesne|Brousse|Chevrot|Crème de camembert|Fromage à effilocher|Fromage à pâte molle et croûte fleurie
|Fromage à pâte molle et croûte lavée|Fromage à pâte molle et croûte mixte colorée|Fromage à pâte molle type carré
|Fromage blanc fondu en portion|Fromage bleu au lait de vache|Fromage de brebis corse à pâte molle
|Fromage de chèvre lactique affiné|Fromage de lactosérum de brebis|Gouda au cumin|Gruyère IGP France
|Mimolette demi-vieille|Mimolette extra-vieille|Mimolette jeune|Mimolette vieille|Spécialité fromagère non affinée en coque
|Spécialité végétale type fromage en tranche|Cantal entre-deux|Cantal jeune|Camembert au lait pasteurisé
|Fromage de brebis à pâte molle et croûte fleurie|Sainte-Maure|Emmental de Savoie|Raclette fumée|Fromage type feta
|Caviar de tomates|Clafoutis aux fruits|Crème aux œufs|Crème brûlée|Crème caramel|Crème dessert|Crème dessert à la vanille
|Crème dessert au café|Crème dessert au caramel|Crème dessert au chocolat|Dessert au soja|Dessert végétal sans soja
|Faisselle au coulis de fruits|Flan aux œufs|Fondant au chocolat noir et crème anglaise|Fromage blanc et crème fouettée
|Gâteau au chocolat|Gâteau de riz au caramel|Gâteau de semoule aux raisins et caramel|Huile de beurre|Ile flottante
|Isolat de soja|Ktipiti|Lait de bufflesse|Lait de grand mélange|Lait de jument|Lait de poule|Lait emprésuré aromatisé
|Lait fermenté à boire|Lait fermenté type yaourt au bifidus|Lait fermenté type yaourt aux esters de stérol
|Lait gélifié aromatisé|Liégeois|Liégeois aux fruits|Matière grasse laitière à 15-25% MG|Matière grasse tartinable
|Matière grasse tartinable à teneur en MG <= 41%|Matière grasse tartinable à teneur en MG > 62%|Milkshake
|Mousse à la crème de marrons|Mousse au chocolat|Mousse au chocolat traditionnelle|Mousse au chocolat végétale
|Mousse aux fruits|Mousse de fromage blanc sur lit de fruits|Mousse liégeoise|Œuf à la coque|Œufs au lait|Œuf au plat
|Œuf brouillé|Œuf cru|Œuf poché|Œuf dur|Omelette à la ciboulette|Omelette au fromage|Omelette aux champignons
|Omelette aux lardons|Omelette nature|Pain perdu|Panna cotta|Profiteroles|Riz au lait|Riz au lait vanille|Semoule au lait
|Spécialité à base de crème légère 4-5% MG|Spécialité à base de crème légère 4% MG environ|Spécialité laitière
|Spécialité laitière riche en protéines|Tiramisu au café|Tiramisu aux fruits|Tortilla espagnole aux oignons
|Yaourt à la grecque sur lit de fruits|Crème pâtissière instantanée|Protéine de soja texturée
|Barre glacée chocolatée|Bûche glacée|Citron givré|Coupe glacée parfum pêche Melba|Coupe glacée type café
|Dessert glacé feuilleté|Dessert glacé type mystère|Dessert glacé type sundae|Nougat glacé|Omelette norvégienne
|Pêche melba|Poire belle Hélène|Profiterole avec glace vanille et sauce chocolat|Glace au yaourt|Glace à l'eau
|Biscotte au germe de blé|Biscotte briochée|Biscotte classique|Brioche aux pépites de chocolat|Brioche fourrée aux fruits
|Brioche fourrée crème pâtissière|Couronne des rois aux fruits confits|Cracker de table au fromage et aux graines
|Cracker de table aux graines|Croissant|Croûton|Galette de céréales soufflées|Galette de maïs soufflé
|Muffin anglais complet|Pain au lait aux pépites de chocolat|Pain aux céréales et/ou aux graines|Pain aux graines sans gluten
|Pain blanc|Pain de mie aux céréales et/ou graines|Pain de mie blanc|Pain de mie blanc sans gluten
|Pain sans gluten précuit|Tartine craquante extrudée|Pain de tradition française|Pain de mie complet|Pain pour burger
|Tortilla souple|Brioche|Pain complet
|Alcool pur|Apéritif anisé sans alcool|Bière ""de spécialités""|Bière ""spéciale""|Bière faiblement alcoolisée
|Bière sans alcool et sans sucres ajoutés|Boisson à l'aloe vera|Boisson à l'eau minérale|Boisson à la châtaigne
|Boisson à la sève de bouleau|Boisson au fruit et au lait|Boisson au soja et jus de fruits concentrés|Boisson au thé
|Boisson concentrée à diluer|Boisson énergisante|Boisson gazeuse à la pomme|Boisson gazeuse aromatisée
|Boisson gazeuse aux fruits|Boisson lactée aromatisée à la fraise|Boisson lactée aromatisée à la vanille
|Boisson lactée aromatisée au café|Boisson lactée aromatisée au chocolat|Boisson plate au fruits et au légume
|Boisson plate aux fruits|Boisson pour le sport|Café au lait|Café décaféiné|Chicorée et café|Chocolat chaud
|Cidre aromatisé aux fruits|Cidre bouché demi-sec|Cidre traditionnel|Cocktail à base de rhum|Cocktail à base de whisky
|Cocktail type punch|Diabolo|Eau de vie de fruits|Jus de cerise des Antilles|Jus de fruit et de légume|Jus de grenade
|Jus de pruneau|Kéfir d'eau et de fruits|Kir|Kir royal|Marsala aux œufs|Nectar de banane|Nectar de fraise
|Nectar de fruit de la passion|Nectar de goyave|Nectar de papaye|Nectar de poire|Nectar de pomme|Nectar multifruit
|Panaché préemballé|Pétillant de fruits|Poudre maltée|Spécialité à diluer au citron pour boissons|Thé infusé|Tisane infusée
|Vin blanc mousseux aromatisé|Smoothie|Boisson au riz|Boisson à la noix de coco|Jus d'orange sanguine|Sangria|Thé noir|Thé vert
|Café expresso|Jus de clémentine|Nectar d'ananas|Nectar de mangue|Nectar de pêche|Jus multifruit
|Barre à la noix de coco|Barre céréalière au chocolat et aux fruits|Barre céréalière diététique hypocalorique
|Barre céréalière ""équilibre"" au chocolat|Barre céréalière ""équilibre"" aux fruits
|Barre céréalière pour petit déjeuner au lait|Barre chocolat au lait avec nougat|Barre chocolatée aux fruits secs
|Barre chocolatée biscuitée|Barre chocolatée non biscuitée enrobée|Biscuit au chocolat fourré parfum vanille
|Biscuit meringué fourré|Biscuit sec à la noix de coco|Biscuit sec à teneur garantie en vitamines|Biscuit sec au sésame
|Biscuit sec au soja|Biscuit sec aux céréales complètes|Biscuit sec aux fruits|Biscuit sec avec tablette de chocolat
|Biscuit sec croquant aux amandes|Biscuit sec feuilleté|Biscuit sec fin|Biscuit sec fourré à la pâte|Biscuit sec fourré aux fruits
|Biscuit sec nature|Biscuit sec petit beurre au blé complet|Biscuit sec petit beurre au chocolat|Biscuit sec pour petit déjeuner
|Biscuit sec sans chocolat|Biscuit sec sans gluten|Blé khorasan complet soufflé|Bonbon dragéifié|Bonbon dur|Bonbon dur et sucette
|Bonbon dur type collier|Bonbon gélifié à la réglisse|Bonbon tendre|Boules de maïs soufflées au miel|Cacahuète caramélisée
|Cacahuète enrobée de chocolat dragéifiée|Calisson d'Aix en Provence|Céréales pour petit déjeuner au miel
|Céréales pour petit déjeuner fourrées au lait|Chewing-gum|Chocolat au lait aux céréales croustillantes
|Chocolat au lait aux fruits secs|Chocolat au lait fourré au praliné|Chocolat au lait sans sucres ajoutés
|Chocolat blanc aux fruits secs|Chocolat noir aux fruits|Chocolat noir aux fruits secs|Chocolat noir fourrage confiseur à la menthe
|Chocolat noir fourré praliné|Chocolat noir sans sucres ajoutés|Cône|Confiserie au chocolat dragéifiée
|Cookie au chocolat et aux fruits à coque|Cookie aux cranberries et au chocolat blanc|Crème de pruneaux|Crêpe dentelle
|Dragée amande|Édulcorant à base d'extrait de stévia|Edulcorant à base de sucre et aspartame|Édulcorant à l'aspartame
|Édulcorant à la saccharine|Florentin|Galette de riz soufflé|Gâteau moelleux fourré au chocolat et au lait|Gaufrette
|Gaufrette fourrée|Gaufrette fourrée et/ou enrobée de chocolat|Goûter sec fourré parfum chocolat|Goûter sec fourré parfum fruits
|Goûter sec fourré parfum lait|Grains de blé soufflés au miel|Macaron sec|Marmelade|Muesli croustillant au chocolat
|Nougat tendre|Pastille rafraîchissante|Pétales de céréales|Pétales de céréales au chocolat|Pétales de céréales aux fruits
|Pétales de céréales aux fruits à coque|Pétales de maïs glacés au sucre|Préparation de fruits divers|Riz soufflé chocolaté
|Rocher chocolat fourré praliné|Gelée de mûre|Confiture de mûre|Confiture de myrtille|Barre céréalière aux fruits
|Bouillon de viande et légumes type pot-au-feu|Coulis de fruits rouges|Court-bouillon pour poissons|Gelée au madère|Gelée royale
|Gélifiant pour confitures|Ketchup allégé en sucres|Lécithine de soja|Levure de bière en paillettes|Mayonnaise à teneur réduite en matière grasse
|Meloukhia|Pollen|Sauce à l'échalote à la crème|Sauce à la crème aux épices|Sauce à la crème aux herbes|Sauce algérienne
|Sauce andalouse|Sauce au beurre|Sauce au fromage pour risotto|Sauce au poivre|Sauce au vin rouge|Sauce au yaourt
|Sauce aux champignons et à la crème|Sauce burger|Sauce kebab|Sauce pommes frites|Sauce pour nems à base de nuoc-mam dilué
|Sauce samouraï|Sauce tomate au fromage|Sauce tomate aux champignons|Sauce tomate aux oignons|Sauce tomate aux olives
|Sauce végétale type bolognaise|Sauce vinaigrette allégée en matière grasse|Sel au céleri|Sirop léger pour fruits appertisés au sirop
|Sirop léger pour poire appertisée|Sirop pour fruits appertisés au sirop|Son de maïs|Son de riz|Extrait de levure|Oignon au vinaigre
|Fond de veau pour sauces et cuisson|Sauce au poivre vert|Sauce à l'oseille|Moutarde à l'ancienne
|Raisin noir Muscat|Pomme Pink lady|Pêche blanche|Pêche jaune|Poire Conférence|Poire Williams|Pomme Gala|Pomme Golden|Pomme Granny Smith
|Tomate allongée|Tomate côtelée|Tomate grappe|Tomate marmande|Tomate noire de Crimée|Tomate ronde|Tomate verte
|Prune violette|Melon miel|Mûre noire|Raisin Chasselas|Purée de fruits|Compote|Chou chinois pé-tsaï|Courge musquée
|Graine germée de lentille|Graine germée de radis|Cresson alénois|Cresson de fontaine|Salsifis noir|Raifort|Pourpier|Bambou
|Chayote|Cardon|Fève à écosser|Lotte|Truite de mer|Thon albacore|Thon germon|Thon rouge|Saint-Pierre|Saumon fumé
|Jus de citron vert|Beurre de cacahuète
'''.replace('\n', '').split('|'))
RETRAITS.discard('')
# Variétés et produits remis après relecture (retirés dans un premier temps).
RESTAURES = ['Pomme Golden', 'Pomme Granny Smith', 'Pomme Pink lady', 'Pomme Gala', 'Poire Williams', 'Poire Conférence', 'Pêche blanche', 'Pêche jaune', 'Tomate grappe', 'Tomate côtelée', 'Tomate allongée', 'Tomate ronde', 'Tomate verte', 'Melon miel', 'Cresson alénois', 'Raifort', 'Pourpier', 'Chayote', 'Cardon', 'Fève à écosser', 'Thon albacore', 'Thon germon', 'Thon rouge', 'Saint-Pierre', 'Lotte', 'Truite de mer', 'Bambou', 'Courge musquée', "Moutarde à l'ancienne", 'Croissant', 'Brioche', 'Pain blanc', 'Pain de mie complet', 'Huître', 'Grondin', 'Mulet', 'Tomate marmande', 'Mûre noire', 'Prune violette', 'Raisin Chasselas', 'Compote', 'Purée de fruits', 'Graine germée de lentille', 'Salsifis noir', 'Thé vert', 'Thé noir', "Jus d'orange sanguine", 'Café expresso', 'Boisson au riz', 'Boisson à la noix de coco', 'Mimolette vieille', 'Cantal jeune', 'Emmental de Savoie', 'Raclette fumée', 'Sainte-Maure', 'Fromage type feta', 'Nectar de mangue', "Nectar d'ananas", 'Jus multifruit', 'Jus de clémentine', 'Biscuit sec nature', 'Pain de tradition française']
RETRAITS |= {'Epinard', 'Ail séché', 'Chipolata cuite', 'Saucisse de Toulouse cuite',
             'Saucisse de Morteau bouillie/cuite à l\'eau', 'Frites de pommes de terre', 'Thon à l\'huile de tournesol', 'Steak haché 5 %',
             'Tzatziki à base de fromage frais', 'Yaourt à la grecque aromatisé', 'Œuf d\'oie', 'Œuf de dinde',
             'Graine germée de luzerne', 'Abricot au sirop léger', 'Ananas au sirop léger'}
RENOMMAGES.update({
    'Salade': 'Frisée', 'Tomate côtelée': 'Tomate cœur de bœuf', 'Biscuit sec aux œufs à la cuillère': 'Biscuits à la cuillère',
    'Biscuit sec petit beurre': 'Petit-beurre', 'Biscuit sec nature': 'Biscuits secs nature',
    'Fromage frais type petit suisse': 'Petit-suisse', 'Préparation culinaire à base de soja': 'Crème de soja',
    'Macédoine': 'Salade de fruits au sirop', 'Rostis': 'Röstis', 'Printanière': 'Jardinière de légumes surgelée',
    'Lentin comestible': 'Shiitaké', 'Graine germée de haricot mungo': 'Pousses de soja', 'Bette': 'Blette',
    'Tzatziki à base de yaourt': 'Tzatziki', 'Yaourt à la grecque nature': 'Yaourt à la grecque',
    'Pain de tradition française': 'Pain de tradition', 'Lait à 1': 'Lait à 1,2 % MG',
    'Fromage type feta': 'Fromage type feta (vache)', 'Chou chinois pak choï': 'Pak choï', 'Graine germée de lentille': 'Lentilles germées',
    'Croquette de pomme de terre': 'Croquettes de pommes de terre', 'Potatoes': 'Potatoes surgelées',
    'Petits pois et carottes': 'Petits pois et carottes surgelés',
    'Légumes pour couscous': 'Légumes pour couscous surgelés', 'Légumes pour potages': 'Légumes pour potage surgelés',
    'Légumes pour ratatouille': 'Légumes pour ratatouille surgelés',
})

RETRAITS -= {'Saumon fumé', 'Beurre de cacahuète'} | set(RESTAURES)  # gardés : listés par erreur ci-dessus

EXCLUDE_GROUPS = {'aliments infantiles', 'entrées et plats composés'}
EXCLUDE_SUB = {'eaux', 'viandes cuites', 'poissons cuits', 'mollusques et crustacés cuits',
               'denrées destinées à une alimentation particulière', 'huiles de poissons', 'gâteaux et pâtisseries', ''}
NO_COOK_PENALTY = {'charcuteries et alternatives végétales', 'produits à base de poissons et produits de la mer'}

COOKED = re.compile(r"\b(cuit|cuite|cuits|cuites|bouilli|bouillie|bouillis|r[oô]ti|r[oô]tie|grill[ée]e?s?|frit|frite|po[eê]l[ée]e?|brais[ée]e?|vapeur|saut[ée]e?|mijot[ée]e?|au four|micro-ondes|cuisin[ée]e?)\b", re.I)
STATE_DROP = re.compile(r"^(cru|crue|crus|crues|frais|fra[iî]che|chair.*|sans peau|avec peau|sans noyau|pel[ée]e?|tout type|nature|[ée]lev.*)$", re.I)
GENERIC_HEAD = {'champignon', 'courge', 'chou', 'poivron'}
ANIMALS = {'boeuf': 'bœuf', 'veau': 'veau', 'porc': 'porc', 'agneau': 'agneau', 'mouton': 'mouton', 'poulet': 'poulet',
           'poule': 'poule', 'dinde': 'dinde', 'canard': 'canard', 'lapin': 'lapin', 'cheval': 'cheval', 'pintade': 'pintade',
           'oie': 'oie', 'caille': 'caille', 'chevreau': 'chevreau', 'sanglier': 'sanglier', 'cerf': 'cerf',
           'chevreuil': 'chevreuil', 'faisan': 'faisan', 'pigeon': 'pigeon', 'autruche': 'autruche'}


def clean(s):
    s = re.sub(r'\s*\([^)]*\)', '', s)
    return re.sub(r'\s+', ' ', s).strip(' ,')


def oe(s):
    s = re.sub(r'\bOe(?=u|i)', 'Œ', s)
    return re.sub(r'oe(?=u|i)', 'œ', s)


def elide(s):
    return re.sub(r"\b(de|De) (?=[aeiouéèêœAEIOUÉÈŒ])", "d'", s)


def first_alt(s):
    return re.split(r'\s+ou\s+', s)[0].strip()


def name_for(r):
    raw = clean(r[7])
    parts = [p.strip() for p in re.split(r',(?!\d)', raw) if p.strip()]
    head = first_alt(parts[0])
    rest = parts[1:]
    hl = norm(head).replace(' ', '')
    if r[4] == 'viandes crues' and hl in ANIMALS and rest:
        cut = first_alt(re.sub(r'\b(cru|crue|crus|crues|avec peau|sans peau|sans os|viande et peau|viande)\b', '', rest[0])).strip(' ,')
        if cut == '':
            return head
        return cut[0].upper() + cut[1:] + ' de ' + ANIMALS[hl]
    if r[4] == 'viandes crues' and len(rest) >= 1 and norm(rest[0]).replace(' ', '') in ANIMALS:
        return head + ' de ' + ANIMALS[norm(rest[0]).replace(' ', '')]  # abats : « Foie, veau »
    if hl in GENERIC_HEAD and rest and not STATE_DROP.match(rest[0]) and not COOKED.search(rest[0]):
        alt = first_alt(rest[0])
        if hl == 'champignon':
            return alt[0].upper() + alt[1:]
        return head + ' ' + alt
    return head


def state_score(r):
    n, sub = r[7].lower(), r[4]
    s = 0
    if COOKED.search(n) and sub not in NO_COOK_PENALTY: s += 50
    if 'prélev' in n: s += 100
    if 'surgel' in n: s += 20
    if 'appertis' in n or 'en conserve' in n: s += 15
    if 'aliment moyen' in n: s += 30
    if re.search(r'all[ée]g|0% mg|[ée]dulcor|r[ée]duite|enrichi|sans gluten|light|sans lactose', n): s += 10
    if 'sucr' in n: s += 3
    if re.search(r'\bcru(e|s|es)?\b', n): s -= 5
    if re.search(r'\b(sec|s[eè]che|secs|s[eè]ches)\b', n) and sub == 'légumineuses': s -= 8
    if 'nature' in n: s -= 2
    return s


AISLE = {
    'fruits': 'fruits', 'légumes': 'fruits', 'pommes de terre et autres tubercules': 'fruits', 'herbes': 'fruits',
    'légumineuses': 'savory', 'fruits à coque et graines oléagineuses': 'savory', 'algues': 'savory',
    'viandes crues': 'butcher', 'autres produits à base de viande': 'butcher',
    'charcuteries et alternatives végétales': 'deli',
    'poissons crus': 'fish', 'mollusques et crustacés crus': 'fish', 'produits à base de poissons et produits de la mer': 'fish',
    'oeufs': 'dairy', 'laits': 'dairy', 'crèmes et spécialités à base de crème': 'dairy',
    'produits laitiers frais et alternatives végétales': 'dairy', 'fromages et alternatives végétales': 'cheese',
    'beurres': 'dairy', 'margarines': 'dairy', 'pâtes à tarte': 'dairy', 'ingrédients pour végétariens': 'dairy',
    'tartinables végétariens': 'dairy', 'autres matières grasses': 'savory', 'huiles et graisses végétales': 'savory',
    'farines': 'savory', 'pâtes, riz et céréales': 'savory', 'biscuits apéritifs': 'savory',
    'pains et assimilés': 'bakery', 'viennoiseries': 'bakery',
    'barres céréalières': 'sweet', 'biscuits sucrés': 'sweet', 'chocolats et produits à base de chocolat': 'sweet',
    'confiseries non chocolatées': 'sweet', 'confitures et assimilés': 'sweet', 'céréales de petit-déjeuner': 'sweet',
    'sucres, miels et assimilés': 'sweet',
    'aides culinaires': 'spices', 'condiments': 'spices', 'sauces': 'spices', 'sels': 'spices', 'épices': 'spices',
    'desserts glacés': 'frozen', 'glaces': 'frozen', 'sorbets': 'frozen', '-': 'frozen',
    'boisson alcoolisées': 'drinks', 'boissons sans alcool': 'drinks',
}
HERBS_DRIED = re.compile(r'séch|poudre', re.I)


def aisle_for(r):
    n = r[7].lower()
    if 'appertis' in n: return 'cans'
    if 'surgel' in n: return 'frozen'
    if r[4] == 'herbes' and HERBS_DRIED.search(n): return 'spices'
    return AISLE.get(r[4], 'savory')


def unit_for(sub, name):
    n = name.lower()
    if n.startswith(('café moulu', 'thé en', 'poudre', 'chicorée', 'cacao', 'lait en poudre')):
        return 'g'
    if sub in ('laits', 'boissons sans alcool', 'boisson alcoolisées') or n.startswith(('jus ', 'lait ', 'boisson', 'nectar')):
        return 'ml'
    if sub == 'huiles et graisses végétales' or n.startswith(('huile', 'vinaigre', 'sauce soja', 'sauce nuoc', "sauce d'huître")):
        return 'cs'
    if sub in ('épices', 'sels'):
        return 'cc'
    if n.startswith('moutarde'):
        return 'cs'
    return 'g'


PLURAL_SUBS = {'fruits', 'légumes', 'pommes de terre et autres tubercules', 'mollusques et crustacés crus', 'viennoiseries'}
# Seuls le premier mot et les adjectifs qui le suivent directement s'accordent.
ADJECTIVES = {'rouge', 'vert', 'verte', 'blanc', 'blanche', 'noir', 'noire', 'jaune', 'rose', 'violet', 'violette', 'doux',
              'douce', 'sec', 'sèche', 'frisé', 'frisée', 'nouvelle', 'creuse', 'plate', 'plat', 'germée', 'sauvage', 'amer',
              'amère', 'musquée', 'chinois', 'mange-tout', 'plantain', 'brut', 'commun', 'commune', 'brioché',
              'ordinaire', 'entier', 'surgelé', 'surgelée'}


def plural_word(w):
    if re.search(r'[sxz]$', w): return w
    if w.endswith(('eau', 'au', 'eu')): return w + 'x'
    if w.lower() in ('chou', 'genou', 'caillou', 'hibou', 'bijou', 'joujou', 'pou'): return w + 'x'
    if w.endswith('al') and w.lower() not in ('bal', 'festival', 'chacal'): return w[:-2] + 'aux'
    return w + 's'


def plural(name):
    words = name.split(' ')
    out = ['-'.join(plural_word(p) for p in words[0].split('-'))]
    agree = True
    for w in words[1:]:
        if agree and w.lower() in ADJECTIVES:
            out.append(plural_word(w))
        else:
            agree = False
            out.append(w)
    p = ' '.join(out)
    p = p[0].lower() + p[1:]
    return None if p.lower() == name.lower() else p


def keep(r):
    return r[3] not in EXCLUDE_GROUPS and r[4] not in EXCLUDE_SUB and r[6].isdigit()


# --- Génération ---------------------------------------------------------------------------------
entries = {}  # clé normalisée → dict


def add(d):
    k = norm(d['nom'])
    if k in entries:
        return
    entries[k] = d


DENSITY_OIL = 0.92
for nom, pluriel, rayon, unite, poids, de_base in EXISTING:
    code = C(CHOIX[nom])
    add({'code': code, 'nom': nom, 'pluriel': pluriel, 'rayon': rayon, 'unite': unite, 'poids': poids if poids is not None else POIDS.get(nom),
         'de_base': de_base, 'origine': 'historique'})

for nom, ref, rayon, unite, pluriel in AJOUTS:
    add({'code': C(ref), 'nom': nom, 'pluriel': pluriel, 'rayon': rayon, 'unite': unite, 'poids': None,
         'de_base': False, 'origine': 'ajout'})

cands = collections.defaultdict(list)
for r in rows:
    if not keep(r):
        continue
    nm = elide(oe(name_for(r)))
    nm = nm[0].upper() + nm[1:] if nm else nm
    if not nm or len(nm) > 48:
        continue
    cands[nm].append((state_score(r), len(r[7]), r))

for nm, lst in sorted(cands.items()):
    lst.sort(key=lambda t: (t[0], t[1]))
    sc, _, r = lst[0]
    if sc >= 30:
        continue  # cuit, « aliment moyen » seul ou propre aux Antilles : pas un ingrédient qu'on achète
    if nm in RENOMMAGES:
        if RENOMMAGES[nm] is None:
            continue
        nm = RENOMMAGES[nm]
    if nm in RETRAITS or nm.replace('"', '""') in RETRAITS:
        continue
    nm = nm.replace('""', '"').replace('"', '')
    sub = r[4]
    add({'code': r[6], 'nom': nm, 'pluriel': plural(nm) if sub in PLURAL_SUBS else None, 'rayon': aisle_for(r),
         'unite': 'cc' if sub == 'herbes' and re.search(r'séch|poudre', r[7]) else unit_for(sub, nm), 'poids': None, 'de_base': False, 'origine': 'ciqual'})

for d in entries.values():
    n = d['nom'].lower()
    sub = BY_CODE[d['code']][4]
    d['densite'] = DENSITY_OIL if (sub == 'huiles et graisses végétales' or n.startswith('huile')) else None
    if d['origine'] != 'historique':
        d['de_base'] = sub in ('épices', 'sels', 'huiles et graisses végétales') or (sub == 'herbes' and 'séch' in BY_CODE[d['code']][7]) \
            or n.startswith(('vinaigre', 'huile'))

out = sorted(entries.values(), key=lambda d: (d['rayon'], norm(d['nom'])))
with open(DST, 'w', newline='', encoding='utf-8') as f:
    w = csv.writer(f, delimiter=';', lineterminator='\n')
    w.writerow(['code', 'nom', 'pluriel', 'rayon', 'unite', 'poids_piece', 'densite', 'de_base', 'aliment_ciqual'])
    for d in out:
        w.writerow([d['code'], d['nom'], d['pluriel'] or '', d['rayon'], d['unite'],
                    '' if d['poids'] is None else ('%g' % d['poids']), '' if d['densite'] is None else d['densite'],
                    1 if d['de_base'] else 0, BY_CODE[d['code']][7]])
print(len(out), 'ingrédients', collections.Counter(d['rayon'] for d in out), file=sys.stderr)
