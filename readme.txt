=== Cjenovna transparentnost ===
Contributors: goranzajec
Donate link: https://svejedobro.hr
Tags: woocommerce, cijene, cjenik, transparentnost
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 11.1
Stable tag: 1.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Uskladenje WooCommerce trgovine s propisima o kontroli cijena: dnevna objava
cjenika i isticanje dodatne (sidrene) cijene.

== Description ==

= Ne dira vase cijene =

Dodatak ne mijenja cijene ni bilo koji drugi podatak o proizvodu — ni nazive,
opise, kategorije ni zalihe. Pise iskljucivo u vlastite tablice. Obrisete li ga,
trgovina je tocno onakva kakva je bila prije.

Ono sto radi: cita cijene, vodi evidenciju o njima i objavljuje datoteku koju
propis trazi.

= Zasto dodaje karticu na proizvod =

Propis trazi podatke koje WooCommerce ne vodi za svaki zapis — barkod po
varijanti, marku uz svaki redak, vrstu snizenja. Kartica "Podaci za cjenik" na
proizvodu postoji da ih ima gdje upisati, ne da mijenja kako trgovina radi.

Gdje WooCommerce ima svoje polje (GTIN u kartici Inventar, marka u kutiji
Marke), vrijedi njegova vrijednost i cita se uzivo. Nasa se ne kopira i ne
prepisuje preko njegove.

Dodatak uz cijenu proizvoda istice dodatnu cijenu na referentni datum i, kod
snizenja, najnizu cijenu u posljednjih 30 dana. Vodi vlastitu evidenciju cijena
da bi te podatke mogao izracunati, a ne pretpostaviti.

= Opseg =

**Dodatak radi iskljucivo s cijenama unesenima u proizvod.** Cijene utipkane u
sadrzaj stranica — baneri, elementi graditelja stranica, opisi proizvoda,
odredisne stranice — odgovornost su vlasnika trgovine. Dodatak ih ne dira, ne
trazi i ne moze za njih jamciti.

Primjer: opis artikla u kojem rukom pise "-50%" ili "posebna ponuda". Ta
tvrdnja o snizenju ne dolazi iz cijene artikla nego je utipkana u tekst, pa
dodatak o njoj ne zna nista. Ostaje na stranici i kad se cijena promijeni.

Ako negdje na stranicama stoji rucno upisana cijena, treba je uskladiti rucno.

= Sve operacije rade iz administracije =

Dodatak ne zahtijeva pristup terminalu. Dugi poslovi rade u pozadini, u malim
komadima, da ne opterete posluzitelj na dijeljenom hostingu.

Za dnevnu objavu treba jedan redak u cPanelu. Dodatak ga ispise gotovog,
preracunatog za vremensku zonu posluzitelja — i za ljeto i za zimu. Ako do
njega ne dodete, objavu guraju posjeti trgovini, ali to nije zamjena: dan bez
ijednog posjeta ostaje bez cjenika.

= Cijene s porezom =

U cjenik izlazi cijena koju kupac stvarno plati. Trgovina koja cijene unosi bez
poreza dobiva u datoteci bruto iznos, jer je to iznos s blagajne — a ne neto
iznos iz polja.

= Varijante =

Svaka varijanta dobiva svoj redak, s vlastitom sifrom, cijenom i zalihom.
Naziv nosi obiljezja po kojima se prepoznaje ("Majica — Velicina: M, Boja:
crna"), jer WooCommerce varijantama ne mijenja naslov pa bi inace vise redaka
nosilo isto ime uz razlicite cijene.

Barkod se vodi po varijanti, marka po proizvodu — kako je i u WooCommerceu.

== Installation ==

1. Dodaci > Dodaj novi > Posalji dodatak i odaberite ZIP.
2. Aktivirajte.
3. U izborniku se pojavi "Cjenik i cijene" > "Prvo postavljanje", cetiri koraka.

Dodatak ne mijenja nijedan podatak o proizvodu, pa ga je sigurno aktivirati i
na trgovini koja radi.

== Frequently Asked Questions ==

= Mijenja li dodatak moje cijene? =

Ne. Ni jednu, nikad. Ne mijenja ni nazive, opise, kategorije ni zalihe. Pise
iskljucivo u vlastite tablice. Obrisete li ga, trgovina je tocno onakva kakva
je bila prije.

= Zasto mi dodaje karticu na proizvod? =

Propis trazi podatke koje WooCommerce ne vodi za svaki zapis — barkod po
varijanti, marku uz svaki redak, vrstu snizenja. Kartica "Podaci za cjenik"
postoji da ih ima gdje upisati.

Gdje WooCommerce ima svoje polje (GTIN u kartici Inventar, marka u kutiji
Marke), vrijedi njegova vrijednost i cita se uzivo. Nasa se ne kopira i ne
prepisuje preko njegove.

= Trebam li cron? =

Da, ako zelite jamciti rok. Bez njega objavu guraju posjeti trgovini, ali dan
bez ijednog posjeta ostaje bez cjenika, a posjet koji posluzi predmemoriju do
dodatka uopce ne dode. Dodatak na to upozorava dok redak nije postavljen.

= Imam vec dodatak koji prikazuje najnizu cijenu u 30 dana =

Tada je nas prikaz zadano iskljucen. Kupac koji vidi dvije tvrdnje o istoj
stvari ne zna kojoj vjerovati, a to je gore nego nijedna.

= Ukljucio sam najnizu cijenu u 30 dana, a ne prikazuje se =

Prikazuje se najmanja cijena koju imamo zabiljezenu, i ne ceka se punih trideset
dana — ako je jedina zabiljezena ona od jucer, onda je ona i najmanja u prozoru.
Prikaz izostaje samo dok u evidenciji nema nijednog zapisa, jer se tada nema sto
prikazati. Prve zatecene cijene biljeze se pri prvoj dnevnoj provjeri.

Provjerite i je li artikl uopce na snizenju: bez snizenja se ta brojka ne
prikazuje, jer bi bila jednaka danasnjoj cijeni.

Dok evidencija ne pokrije punih 30 dana, ekran Stanje nosi ogradu: ako je artikl
prije nase prve biljeske bio jeftiniji, toga u brojci nema. Imate li stariju
povijest iz drugog dodatka, uvoz odmah popunjava prozor.

= Uveo sam novi proizvod. Koja mu je sidrena cijena? =

Cijena koju ste formirali kad ste ga prvi put uvrstili u ponudu, uz datum kad je
formirana. Sidrena i vazeca cijena su mu na pocetku iste, a uz njih stoji datum
uvodenja umjesto opceg referentnog datuma.

Dodatak to radi sam: prvu cijenu novog artikla zabiljezi cim artikl nastane, a
jednom dnevno prode kroz sve koje jos nije obuhvatio. Ne trazi od vas nista.

U cjeniku uz takav artikl izlazi i element s datumom, jer bi brojka bez njega
tvrdila nesto o opcem referentnom datumu — a to za njega ne vrijedi. Kod svih
ostalih artikala tog elementa nema.

Iznimka je uska: artikli uvedeni izmedu referentnog datuma i instalacije dodatka.
Njima datum znamo, a pocetnu cijenu nismo imali tko zabiljeziti — ekran Stanje ih
prijavi i cekaju vas unos.

= Sidrena cijena mi se ne prikazuje =

Ako tema cijenu ispisuje na svoj nacin, uobicajeni put je ne moze dopuniti.
Tada se dopisuje u pregledniku — postavka "Dopisi sidrenu cijenu i ondje gdje
je tema nije ispisala", zadano ukljucena.

Ako artikla nema u evidenciji, prikaza nema ni na koji nacin. To pise na ekranu
Stanje, s popisom.

== Changelog ==

= 1.4.0 =
* Odluka o sidrenoj cijeni za artikle koji su na referentni datum bili na akciji
  sada ima gdje biti donesena. Ekran Artikli dobio je karticu "Koja je cijena
  vrijedila na referentni datum" s oba kandidata i skupnim upisom — oznacite
  artikle i recite vrijedi li redovna ili akcijska.
* Dotad je nalaz govorio "pogledajte popis i odlucite", a gumb je vodio na ekran
  s posve drugom tablicom: onom o nazivu tekuce akcije. Dva razlicita pitanja
  izgledala su kao jedno, a odluka se nije imala gdje donijeti.
* Odluka se biljezi kao vlastiti izvor i ponovno pokretanje posla je vise ne
  prepisuje. Cijene u trgovini se ne mijenjaju — bira se samo koja se brojka
  objavljuje.

= 1.3.4 =
* Artikl uveden nakon referentnog datuma vise ne trazi unos kad mu je cijena
  zabiljezena, ali bez pouzdanog pocetka. Takav je zapis nastao u dnevnoj
  provjeri: vrijednost zna, trenutak ne. Ranije je artikl zbog toga zavrsavao u
  nalazu i cekao da trgovac prepise brojku koju mu sami prikazujemo.
* Datum uz takvu sidrenu cijenu je datum uvodenja artikla iz WordPressa — znamo
  ga na sekundu, tocniji je od trenutka nase biljeske. Da pocetak nije neovisno
  datiran biljezi se uz zapis, kao i dosad kod svake takve vrijednosti.
* Unos se trazi jos samo kad o cijeni artikla nema NIJEDNOG zapisa.

= 1.3.3 =
* Nalaz vise ne nudi gumb za postupak koji se u tom trenutku ne moze pokrenuti.
  Gumb "Svedi na dvije decimale" stajao je i dok potvrda nije bila dana, pa klik
  nije mijenjao nista, a nalaz bi se pojavio ponovno — jednak kao prije. Sada na
  njegovu mjestu pise sto je prije toga potrebno, uz poveznicu na to mjesto.
* Dvije zapreke imenovale su ekrane kojih vise nema ("Sidrene cijene",
  "Akcijska u redovnu"). Zapreka sada kaze sto nedostaje, a poveznicu nosi nalaz —
  ondje gdje se mijenja zajedno s njim.

= 1.3.2 =
* Novi artikl dobiva sidrenu cijenu odmah, na kraju zahtjeva u kojem je nastao.
  Dotad ju je dobivao tek pri dnevnom prolazu u 05:30, pa bi artikl objavljen u
  podne pola dana stajao bez obveznog podatka — i to bez ijedne poruke. Dnevni
  prolaz ostaje kao mreza ispod toga.
* Ciscenje predmemorije prikaza sada cisti i sloj ispod. Bez toga bi mjerenje
  koje isti artikl ispituje u vise stanja dobilo sidrenu cijenu od prije promjene.

= 1.3.1 =
* Rucni unos sidrene cijene vise ne pomice datum na koji se ona odnosi. Kod
  artikla uvedenog nakon referentnog datuma unos bi mu ujedno postavio opci datum,
  pa bi brojka i natpis uz nju tvrdili razlicito — a upravo na taj unos poziva
  nalaz uveden u 1.3.0.
* Referentni datum se svugdje cita iz postavki. Na sedam mjesta je jos stajala
  konstanta: dva posla koja po njemu racunaju, upis praznog retka, izvoz, naziv
  datoteke, dijagnostika i natpis na ekranu Pregled.

= 1.3.0 =
* Artikl uveden nakon referentnog datuma vise nema praznu sidrenu cijenu. Sidrena
  mu je cijena po kojoj je prvi put ponuden, a referentni datum tog artikla je dan
  kad je formirana. Tako stoji u tumacenju Ministarstva gospodarstva iznesenom na
  radionicama HOK-a u rujnu 2026.; materijal nije javno objavljen, pa je tumacenje
  u kodu zabiljezeno s tom ogradom.
* Ranije je takav artikl imao prazno polje. To je imalo posljedicu koju nitko nije
  trazio: artikl koji ERP ponovno uveze dobiva novi ID i sidrena cijena mu tiho
  nestane iz obvezne objave.
* Uz cijenu takvog artikla stoji njegov datum, ne opci. U cjeniku uz brojku ide i
  element s datumom — ali samo ondje gdje se datum razlikuje od opceg, jer bi ga
  inace svaki redak ponavljao bez potrebe.
* Novi dnevni posao "Sidrena cijena za novododane artikle" prolazi samo kroz
  artikle koje utvrdivanje jos nije obuhvatilo. Ranije ih nije obuhvacalo nista:
  veliki posao je korak pripreme i prode katalog jednom.
* Referentni datum se sada i pri racunanju cita iz postavki, po skupini proizvoda.
  Dotad je posao koristio konstantu, a prikaz postavku — brojka i natpis uz nju
  mogli su tvrditi razlicito.
* Novi nalaz za artikle uvedene izmedu referentnog datuma i instalacije dodatka:
  datum im znamo, pocetnu cijenu ne, i ceka se unos trgovca.

= 1.2.1 =
* Najniza cijena u 30 dana sada izlazi i na varijabilnom proizvodu. Ranije na
  njemu nije izlazila nikad: roditelj je tu brojku imao tvrdo iskljucenu, a blok
  s cijenom varijante WooCommerce uopce ne iscrta kad sve varijante imaju istu
  cijenu — pa je nije imao tko ispisati.
* Vrijedi isto pravilo kao za sidrenu: prikazuje se samo ako su SVE varijante
  snizene i ako je brojka svima ista. Inace roditelj suti, jer bi tvrdnja
  vrijedila samo za dio varijanti, a kupac ne vidi za koje.

= 1.2.0 =
* Najniza cijena u 30 dana vise ne ceka da evidencija pokrije punih trideset
  dana. Prikazuje se najmanja zabiljezena cijena u prozoru — ako je jedina
  zabiljezena ona od jucer, onda je ona i najmanja, jer druge nije bilo.
  Raniji uvjet je pogresno citao propis: "najniza u 30 dana" nije tvrdnja da je
  cijena stara trideset dana. Uz to je stvarao gore stanje od onoga koje je htio
  sprijeciti — nova trgovina ostajala bi trideset dana bez obveznog podatka.
* Dok prozor nije pun, ekran Stanje nosi ogradu: ako je artikl prije prve
  biljeske bio jeftiniji, toga u brojci nema. Ograda ide trgovcu, a ne kao
  sutnja prema kupcu, i sama nestaje kad prozor bude pun.
* Prikaz izostaje jos samo dok u evidenciji nema nijednog zapisa.

= 1.1.2 =
* Ekran Artikli vise nema tablicu za rucni unos u nizu. Podaci ulaze na dva
  nacina: skupno uvozom tablice ili prikupljanjem onoga sto trgovina vec ima,
  a pojedinacno na samom proizvodu, u kartici "Podaci za cjenik" — gdje su i
  polja kojih na ekranu Artikli nikad nije ni bilo.
* Brojke o tome sto nedostaje ostaju na ekranu, ali vise nisu poveznice: vodile
  su u filtar tablice koje nema.

= 1.1.1 =
* Kad je najniza cijena u 30 dana ukljucena a evidencija jos prazna, ekran
  Stanje to sada javlja. Ranije je sutio, pa se na novoj instalaciji cinilo da
  postavka ne radi.
* Redak sa sidrenom cijenom je manji i vise nije podebljan, i velicina mu vise
  ne ovisi o temi.

= 1.1.0 =
* Kad dnevno pokretanje zakaze, obradu guraju posjeti trgovini — nakon sto je
  stranica poslana, pa kupac ne ceka. Nije zamjena za cron i ne gasi upozorenje
  o njemu.
* Sidrena cijena se dopisuje JavaScriptom na temama koje cijenu ispisuju mimo
  uobicajenog puta, i u bloku "All Products". Ucitava se samo na stranicama na
  kojima uobicajeni put nije radio.
* Obje rezerve su postavke, obje zadano ukljucene.

= 1.0.1 =
* Naziv varijante nosi obiljezja po kojima se prepoznaje. Ranije je vise redaka
  nosilo isto ime uz razlicite cijene — na testnom katalogu 55 % datoteke.
* U cjenik izlazi cijena S POREZOM. Trgovina koja cijene unosi bez poreza
  ranije bi objavila neto iznos, a kupac placa bruto.
* Artikl bez sifre dobiva oznaku umjesto praznog obveznog polja.
* Izjava o kompatibilnosti s HPOS-om i blokovima kosarice.

= 1.0.0 =
* Prva verzija. Sidrena cijena uz cijenu, dnevna objava cjenika u .xml i .csv,
  uvoz iz ERP-a, carobnjak za prvo postavljanje.
