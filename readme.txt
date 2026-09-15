=== Cjenovna transparentnost ===
Contributors: goranzajec
Donate link: https://svejedobro.hr
Tags: woocommerce, cijene, cjenik, transparentnost
Requires at least: 5.9
Requires PHP: 7.4
Stable tag: 1.0.1
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
