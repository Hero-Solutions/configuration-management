# ResourceSpace: previewtaken voor ontbrekende resources

## Status en oorzaak

Deze correctie is lokaal voorbereid op 19 oktober 2026. Productie is niet
benaderd. Alle servercommando's hieronder zijn uitsluitend voor de beheerder
om zelf handmatig uit te voeren. Ook controlecommando's voert de agent niet uit.

De aangeleverde logs tonen dat `delete_previews()` stopt wanneer
`get_resource_data($resource, false)` geen resource vindt. De previewtaak
controleert alleen of het nummer positief is. Daardoor wordt de normale
foutafhandeling niet bereikt en breekt de wachtrijverwerking af.

De [patch](files/missing-preview-resource.patch) controleert de resource voordat
previews worden verwijderd. Als de resource ontbreekt:

- krijgt alleen die taak `STATUS_ERROR`;
- komen het taaknummer en resourcenummer in de uitvoer van de wachtrij;
- verlaat `return` uitsluitend de ingeladen handler, zodat de aanroepende
  wachtrij verder kan gaan.

De taak blijft als mislukt zichtbaar. De patch verwijdert geen taken of
resources. Bestaande verwerking van geldige resources blijft behouden.

Dit is een gerichte correctie voor een resource die ontbreekt bij het begin
van de taak, geen algemene afhandeling van alle mogelijke verwerkingsfouten.
De patch is gebaseerd op het door de gebruiker aangeleverde handlerfragment.
De precieze geïnstalleerde versie en de volledige ResourceSpace-code zijn niet
lokaal beschikbaar. De patch wordt niet automatisch door Ansible uitgerold.

## Lokale controle

Vanuit `configuration-management`:

```powershell
php -n tests/resourcespace-preview-job.php
```

De fixture bevat uitsluitend de aangeleverde regels 1–51 van de oorspronkelijke
handler. De test gebruikt een tijdelijke lokale map en eigen stubs. Hij laadt
geen ResourceSpace-configuratie of database en gebruikt geen netwerk.

Gecontroleerd op PHP 8.4.3: oorspronkelijke crash, toepasbaarheid en syntaxis
van de daadwerkelijke patch, foutstatus en logging voor de ontbrekende resource,
doorgaan naar de volgende taak, behoud van previewparameters en bestaande
succes- en foutafhandeling. Dit is geen test van de volledige installatie.

## Handmatig toepassen door de beheerder

Wacht tot de huidige wachtrijverwerking klaar is. Plaats de patch zelf als
`/root/missing-preview-resource.patch` op de server, buiten de webmap.
Voer onderstaande stappen zelf uit als root. Stop bij iedere fout.

1. Controleer of de patch exact past. Dit wijzigt het bronbestand niet:

   ```bash
   cd /data/resourcespace
   patch --dry-run --fuzz=0 -p1 < /root/missing-preview-resource.patch
   ```

   Bij een reeds toegepaste, omgekeerde of niet-passende patch: niet forceren.

2. Maak een unieke reservekopie buiten de webmap. Bewaar het getoonde pad:

   ```bash
   preview_fix_backup=$(mktemp -d /root/resourcespace-preview-backup.XXXXXX)
   cp -p /data/resourcespace/include/job_handlers/create_previews.php "$preview_fix_backup/create_previews.php"
   printf '%s\n' "$preview_fix_backup/create_previews.php"
   ```

3. Pas uitsluitend deze patch toe en controleer de PHP-syntaxis:

   ```bash
   patch --fuzz=0 -p1 < /root/missing-preview-resource.patch
   php -n -l /data/resourcespace/include/job_handlers/create_previews.php
   ```

   Bij een fout: herstel eerst de reservekopie. In dezelfde terminal:

   ```bash
   cp -p "$preview_fix_backup/create_previews.php" /data/resourcespace/include/job_handlers/create_previews.php
   ```

4. Laat de bestaande geplande verwerking haar werk doen. Een toekomstige
   ontbrekende resource wordt als mislukte taak bewaard, met bijvoorbeeld:

   ```text
    - Resource #8420 not found; skipping preview job #2.
   ```

   Controleer dan dat volgende taken verder worden verwerkt. Maak hiervoor
   geen ontbrekende testresource of testtaak op productie aan.

Bij een ResourceSpace-update moet opnieuw worden bekeken of deze lokale
correctie nog nodig is en behouden is gebleven. Deze patch past alleen op de
aangeleverde codevariant; forceer hem niet op een andere versie.
