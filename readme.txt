=== Berner Turf Produkter ===
Stable tag: 0.5.2
Requires at least: 6.2
Requires PHP: 7.4
License: GPL-2.0-or-later

Egen produktkatalog och produktsida för bernerturf.com.

== Kortkoder ==
* [bernerturf_produkter land="auto" artikelsida="" rubrik="" ingress=""]
  Katalog med sök, filter på leverantör, kategori och land (flaggor) samt sortering.
* [bernerturf_produkt id="3068" kontakt=""]
  Produktsida. Visar ?produkt=ID från adressen om den produkten har ny produktsida påslagen, annars id.

== Excel är modern (från 0.4.0) ==
* Produkter > Importera från Excel: ladda upp produktfilen (.xlsx) och ev. bilder/säkerhetsdatablad
  (eller en .zip), granska förhandsvisningen, tryck Godkänn. Varje import kan ångras.
* Kolumnen Webb styr var produkten visas: Alla, SE, NO, FI, Baltikum, kombinationer, eller Deleted.
* Texter per språk (fi, sv, en, no). Tomma celler översätts av Claude från finskan vid importen.
  Egen text vinner alltid. Produktnamn översätts aldrig. Baltikum och engelska sajten visar engelska.
* Filnamn styr bilder och säkerhetsdatablad: "GreenCare 8.jpg", "GreenCare 8 SDS SV.pdf" (SV/FI/EN/NO).
* API-nyckel för Claude: fliken Inställningar (bara administratörer), eller BTP_ANTHROPIC_KEY i wp-config.php.
* Importerade produkter visar "Styrs från Excel" i WP-admin; ändringar görs i Excel.

== Automatisk import från SharePoint (från 0.5.0) ==
* Inställningar > Automatisk import från SharePoint: länk till Excel-filen och (valfritt) mappen med bilder och
  säkerhetsdatablad, Tenant ID / Client ID / Client secret för en Microsoft Entra-app (Sites.Selected, läsrätt).
* Varje natt (standard 03:00): om filen eller mappen har ändrats importeras den automatiskt, med översättning.
  Oförändrad fil = inget händer. Bara nya/ändrade bilder hämtas.
* Skyddsspärr: om filen har under 70 % av förra gångens produkter, eller fler än 10 % (minst 6) produkter
  skulle döljas på en gång, genomförs inget – importen väntar på godkännande på importsidan.
* Rapport via e-post när något ändrats, väntar eller gått fel. Knapparna "Testa anslutning" och "Kör nu".
* Uppgifterna kan också ligga i wp-config.php: BTP_MS_TENANT, BTP_MS_CLIENT, BTP_MS_SECRET, BTP_ANTHROPIC_KEY.
* Bygger på WP-Cron. Sajter med lite trafik nattetid: be webbyrån köra wp-cron.php med serverns cron.

== Uppdateringar ==
* Nya versioner publiceras i https://github.com/Damma65/bernerturf-produkter (update.json + releases/*.zip)
  och visas under Tillägg, där en administratör installerar dem. Inställningar visar version och har "Sök efter uppdatering nu".
* Från 0.5.2 installeras inget automatiskt. define( 'BTP_ENABLE_AUTO_UPDATE', true ); slår på automatisk installation (BTP_DISABLE_AUTO_UPDATE stänger alltid av den).
* define( 'BTP_UPDATE_REPO', '' ); = ingen uppdateringskoll alls.

== Ändra innehåll utan ny version ==
* Produkter > redigera produkt > rutan "Berner Turf – ny produktsida":
  ny produktsida på/av, svensk kategori, ingress, beskrivning, punktlista,
  näringsämnen (namn | värde | grön/ljusgrön/beige/grå), användning (# rubrik), förpackning.
* Produkter > Leverantörer: lägg till leverantörer och märk produkterna. Fälten btp_ordning, btp_farg och btp_snart styr ordning, färg och "snart".
* Allt finns i REST-API:t (wp/v2/berner-products, meta btp_*; wp/v2/btp-leverantor).

== Data ==
* Temats egna produktfält ändras aldrig. Tomma fält faller tillbaka på temats text.
* Inga egna tabeller. Ett WP-Cron-jobb (btp_auto_import) bara om automatisk import är påslagen.
* CSS och JS laddas bara där kortkoderna används.
* Externa anrop: bara vid import – api.anthropic.com (översättning), login.microsoftonline.com och
  graph.microsoft.com (SharePoint, endast läsning) samt bildlänkar från Excel.
* Importfiler sparas skyddade i uploads/btp-import/ (de 10 senaste importerna).

== Ta bort ==
Inaktivera och radera pluginet. Fälten btp_* och leverantörstermerna ligger kvar i databasen men används inte av något annat.

== Ändringslogg ==
= 0.5.2 =
* Säkerhet: nya versioner installeras inte längre automatiskt. De visas under Tillägg och installeras för hand av en administratör. Automatiken slås bara på med define( 'BTP_ENABLE_AUTO_UPDATE', true ); i wp-config.php.

= 0.5.1 =
* Produktens egen adress (/product/…) visar den nya produktsidan när produkten har den påslagen (sätts av importen). Temats sidhuvud och sidfot används. Stängs av med BTP_DISABLE_SINGLE_TEMPLATE.
* Katalogkorten länkar till produktens egen adress.
* SEO: katalogens rubrik är H1, produktbilderna har produktnamnet som alt-text, och produktsidorna får metabeskrivning från ingressen i Excel när Yoast saknar en egen.
* Katalogens val (leverantör, kategori, land, sökning, sortering, antal per rad, infotext) sparas i adressen. Tillbaka från en produkt visar samma urval på samma plats i listan, och ett filtrerat urval kan delas som länk.
* Språkkopior (WPML): svenska, finska och norska sajten visar texterna från Excel även när temat har en egen kopia av produkten per språk. Saknas kopia på besökarens språk (t.ex. norska) visas produkten ändå i katalogen.
* Säkrare översättning: ett anrop per språk, svaret kommer i fast format via verktyg (inget fritt JSON), nytt försök vid avklippta svar och tillfälliga fel.

= 0.5.0 =
* Automatisk nattlig import direkt från SharePoint via Microsoft Graph (endast läsrätt), med bildmapp,
  ändringskontroll, översättning i omgångar, skyddsspärr och e-postrapport.
* Uppdaterar sig själv från GitHub (kan stängas av i wp-config.php).
= 0.4.1 =
* Ny bild med samma filnamn byter bilden (filerna jämförs på innehåll).
* Handskriven text i en språkcell är låst och ändras aldrig automatiskt. Claudes oförändrade översättningar i den ifyllda Excel-filen räknas fortfarande som automatiska och uppdateras när finskan ändras.
= 0.4.0 =
* Import från Excel med förhandsvisning, godkännande, logg och ångra. Ifylld Excel (nya id + översättningar) kan laddas ner.
* Språkstöd: svenska, finska, engelska, norska – texter, produktnamn, kategorier och sajtens fasta texter. Övriga språk visar engelska.
* Översättning med Claude (Anthropic API) med ordlista för fackord. Översätter bara ny eller ändrad källtext.
* Synlighet per sajt (Webb/Deleted). Nya produkter publiceras när de har bild.
* Bilder och säkerhetsdatablad via filnamn, länk i Excel eller mediabiblioteket. Flera datablad per språk.
= 0.3.5 =
* Ny filterpanel i SaaS-stil: Filter/Rensa alla överst, fällbara grupper, kryssrutor med antal, Godkänd i längst ner som landsbrickor.
* Förvalt land följer språkversionen besökaren är på (svenska sajten → Sverige, norska → Norge osv.). land="auto" är standard.
= 0.3.4 =
* Leverantörsetiketten på katalogkorten är en liten grå fyrkantig ruta (som på sajten idag). Leverantörsfärgerna finns kvar på prickarna i filtret.
= 0.3.3 =
* Leverantörsetiketterna i leverantörernas egna färger: Barenbrug gul, DCM röd, Syngenta grön, Indigrow lila, Haifa ljusgrön.
= 0.3.2 =
* Katalogkorten utan "Läs mer" (hela kortet är klickbart) och ca 25 % lägre, med större produktbild i rutan.
= 0.3.1 =
* Katalogen använder korten från förslag A (leverantörsetikett, kategori, namn, Läs mer) med helvit bildruta, i ett rutnät med 3–6 per rad och knapp för infotext.
= 0.3.0 =
* Katalog i hyllor per kategori med nya kort (vit bild, vit namnruta med Berner-blå linje), 3–6 per rad, knapp för infotext, inga flaggor på korten.
* Produktsidan i broschyrspalter: Beskrivning, Användning, Näringsämnen/Förpackning/Säkerhetsdatablad. Flaggor uppe till höger. Ny färg för näringsämnen: ljusgrön.
= 0.2.1 =
* Katalogkorten visar bara bild, kategori, namn, flaggor och Läs mer. Beskrivningen visas på produktsidan.
= 0.2.0 =
* Redigerbara fält på produkten och taxonomin Leverantörer, ruta i WP-admin, en produktsida för alla produkter via ?produkt=ID.
= 0.1.0 =
* Första demo.
