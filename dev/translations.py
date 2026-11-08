#!/usr/bin/env python3
# Builds the four XLF files from one table, so that a string can never exist in
# one language and be missing from another.
#
# Usage: python3 dev/translations.py
# Add the English string as the key and its French, Spanish and German forms as
# the tuple, run the script, commit the table and the regenerated files together.
# Never edit the XLF files by hand: the next run overwrites them.
import hashlib, html, json, os

T = {
"O'So MCP Server": (
 "O'So MCP Server",
 "O'So MCP Server",
 "O'So MCP Server"),
"Lets AI assistants such as Claude, ChatGPT or Le Chat connect to this shop, with each employee approving what they may do.": (
 "Permet aux assistants IA comme Claude, ChatGPT ou Le Chat de se connecter à cette boutique, chaque employé validant ce qu'ils ont le droit de faire.",
 "Permite que asistentes de IA como Claude, ChatGPT o Le Chat se conecten a esta tienda, y cada empleado aprueba lo que pueden hacer.",
 "Ermöglicht KI-Assistenten wie Claude, ChatGPT oder Le Chat, sich mit diesem Shop zu verbinden, wobei jeder Mitarbeiter freigibt, was sie tun dürfen."),
"Uninstalling deletes every connected assistant, approval and token. Assistants connected right now lose their access immediately. Continue?": (
 "La désinstallation supprime tous les assistants connectés, les autorisations et les jetons. Les assistants connectés en ce moment perdent leur accès immédiatement. Continuer ?",
 "La desinstalación elimina todos los asistentes conectados, las autorizaciones y los tokens. Los asistentes conectados ahora mismo pierden su acceso de inmediato. ¿Continuar?",
 "Bei der Deinstallation werden alle verbundenen Assistenten, Freigaben und Token gelöscht. Aktuell verbundene Assistenten verlieren sofort ihren Zugang. Fortfahren?"),
"The %hook% hook could not be registered, and the module cannot answer without it.": (
 "Le hook %hook% n'a pas pu être enregistré, et le module ne peut pas répondre sans lui.",
 "No se ha podido registrar el hook %hook%, y el módulo no puede responder sin él.",
 "Der Hook %hook% konnte nicht registriert werden, und ohne ihn kann das Modul nicht antworten."),
"The module tables could not be created.": (
 "Les tables du module n'ont pas pu être créées.",
 "No se han podido crear las tablas del módulo.",
 "Die Tabellen des Moduls konnten nicht angelegt werden."),
"The module pages could not be installed.": (
 "Les pages du module n'ont pas pu être installées.",
 "No se han podido instalar las páginas del módulo.",
 "Die Seiten des Moduls konnten nicht installiert werden."),
"The assistant has been disconnected.": (
 "L'assistant a été déconnecté.",
 "El asistente ha sido desconectado.",
 "Der Assistent wurde getrennt."),
"The log has been emptied.": (
 "Le journal a été vidé.",
 "El registro se ha vaciado.",
 "Das Protokoll wurde geleert."),
"Settings saved.": (
 "Réglages enregistrés.",
 "Ajustes guardados.",
 "Einstellungen gespeichert."),
"This connection request has expired or has already been answered. Start the connection again from your assistant.": (
 "Cette demande de connexion a expiré ou a déjà reçu une réponse. Relancez la connexion depuis votre assistant.",
 "Esta solicitud de conexión ha caducado o ya ha sido respondida. Vuelva a iniciar la conexión desde su asistente.",
 "Diese Verbindungsanfrage ist abgelaufen oder wurde bereits beantwortet. Starten Sie die Verbindung erneut aus Ihrem Assistenten."),
"Sign in with your back office account to see what it asks for and answer.": (
 "Connectez-vous avec votre compte du back-office pour voir ce qu'elle demande et répondre.",
 "Inicie sesión con su cuenta del back office para ver lo que solicita y responder.",
 "Melden Sie sich mit Ihrem Backoffice-Konto an, um zu sehen, was sie anfragt, und zu antworten."),
"Email address": (
 "Adresse e-mail",
 "Dirección de correo electrónico",
 "E-Mail-Adresse"),
"Password": (
 "Mot de passe",
 "Contraseña",
 "Passwort"),
"Sign in": (
 "Se connecter",
 "Iniciar sesión",
 "Anmelden"),
"These sign-in details are not accepted.": (
 "Ces identifiants ne sont pas acceptés.",
 "Estos datos de acceso no se aceptan.",
 "Diese Anmeldedaten werden nicht akzeptiert."),
"Answers will be sent to": (
 "Les réponses seront envoyées à",
 "Las respuestas se enviarán a",
 "Antworten werden gesendet an"),
"Only continue if this address belongs to the assistant you are connecting yourself. If you did not start this connection, close this page.": (
 "Ne continuez que si cette adresse appartient à l'assistant que vous êtes vous-même en train de connecter. Si vous n'avez pas lancé cette connexion, fermez cette page.",
 "Continúe solo si esta dirección pertenece al asistente que usted mismo está conectando. Si no ha iniciado esta conexión, cierre esta página.",
 "Fahren Sie nur fort, wenn diese Adresse zu dem Assistenten gehört, den Sie selbst gerade verbinden. Wenn Sie diese Verbindung nicht gestartet haben, schließen Sie diese Seite."),
"Sending a file to an assistant": (
 "Envoi d'un fichier à un assistant",
 "Envío de un archivo a un asistente",
 "Senden einer Datei an einen Assistenten"),
"The page where you send a file to an assistant can be reached.": (
 "La page où vous envoyez un fichier à un assistant est accessible.",
 "La página en la que envía un archivo a un asistente es accesible.",
 "Die Seite, auf der Sie einem Assistenten eine Datei senden, ist erreichbar."),
"Too many attempts. Wait 15 minutes, then try again.": (
 "Trop de tentatives. Attendez 15 minutes, puis réessayez.",
 "Demasiados intentos. Espere 15 minutos y vuelva a intentarlo.",
 "Zu viele Versuche. Warten Sie 15 Minuten und versuchen Sie es dann erneut."),
"A new assistant is connected to your shop": (
 "Un nouvel assistant est connecté à votre boutique",
 "Un nuevo asistente está conectado a su tienda",
 "Ein neuer Assistent ist mit Ihrem Shop verbunden"),
"Consent page": (
 "Page de consentement",
 "Página de consentimiento",
 "Freigabeseite"),
"The page where an employee signs in and approves a new assistant. Turn it on while you connect one, then off again: the assistants already connected keep working, and while it is off a link someone sends you leads nowhere.": (
 "La page où un employé se connecte et autorise un nouvel assistant. Activez-la le temps d'en connecter un, puis désactivez-la : les assistants déjà connectés continuent de fonctionner, et tant qu'elle est désactivée, un lien que quelqu'un vous envoie ne mène nulle part.",
 "La página en la que un empleado inicia sesión y autoriza a un nuevo asistente. Actívela mientras conecta uno y desactívela después: los asistentes ya conectados siguen funcionando y, mientras esté desactivada, un enlace que alguien le envíe no lleva a ninguna parte.",
 "Die Seite, auf der sich ein Mitarbeiter anmeldet und einen neuen Assistenten freigibt. Schalten Sie sie ein, während Sie einen verbinden, und danach wieder aus: Bereits verbundene Assistenten funktionieren weiter, und solange sie aus ist, führt ein Link, den Ihnen jemand schickt, ins Leere."),
"Switched off: no new assistant can be connected, and this address is the shop's \"page not found\". Turn on the consent page in Access and security while you connect one. The assistants already connected keep working.": (
 "Désactivée : aucun nouvel assistant ne peut être connecté, et cette adresse affiche la page « introuvable » de la boutique. Activez la page de consentement dans Accès et sécurité le temps d'en connecter un. Les assistants déjà connectés continuent de fonctionner.",
 "Desactivada: no se puede conectar ningún asistente nuevo y esta dirección muestra la página «no encontrada» de la tienda. Active la página de consentimiento en Acceso y seguridad mientras conecta uno. Los asistentes ya conectados siguen funcionando.",
 "Ausgeschaltet: Es kann kein neuer Assistent verbunden werden, und diese Adresse zeigt die Seite „nicht gefunden“ des Shops. Schalten Sie die Freigabeseite unter Zugang und Sicherheit ein, während Sie einen verbinden. Bereits verbundene Assistenten funktionieren weiter."),
"Your code to connect an assistant": (
 "Votre code pour connecter un assistant",
 "Su código para conectar un asistente",
 "Ihr Code zum Verbinden eines Assistenten"),
"Check your e-mail": (
 "Vérifiez votre e-mail",
 "Revise su correo",
 "Prüfen Sie Ihre E-Mails"),
"Enter the 6-digit code sent to": (
 "Saisissez le code à 6 chiffres envoyé à",
 "Introduzca el código de 6 cifras enviado a",
 "Geben Sie den 6-stelligen Code ein, der gesendet wurde an"),
"Verification code": (
 "Code de vérification",
 "Código de verificación",
 "Bestätigungscode"),
"Digit": (
 "Chiffre",
 "Cifra",
 "Ziffer"),
"Verify": (
 "Valider",
 "Validar",
 "Bestätigen"),
"The code works for %minutes% minutes and only once.": (
 "Le code est valable %minutes% minutes et ne sert qu'une seule fois.",
 "El código es válido durante %minutes% minutos y solo sirve una vez.",
 "Der Code gilt %minutes% Minuten und nur ein einziges Mal."),
"This code is not right.": (
 "Ce code n'est pas le bon.",
 "Este código no es correcto.",
 "Dieser Code ist nicht richtig."),
"This code no longer works. Ask for a new one.": (
 "Ce code ne fonctionne plus. Demandez-en un nouveau.",
 "Este código ya no funciona. Pida uno nuevo.",
 "Dieser Code funktioniert nicht mehr. Fordern Sie einen neuen an."),
"This is the last code: no other will be sent.": (
 "C'est le dernier code : aucun autre ne sera envoyé.",
 "Es el último código: no se enviará ningún otro.",
 "Das ist der letzte Code: Es wird kein weiterer gesendet."),
"Send a new code": (
 "Renvoyer un code",
 "Enviar un código nuevo",
 "Neuen Code senden"),
"Send a new code in %time%": (
 "Renvoyer un code dans %time%",
 "Enviar un código nuevo en %time%",
 "Neuen Code senden in %time%"),
"Every code of this connection has been used up. It is closed: start it again from your assistant.": (
 "Tous les codes de cette connexion ont été utilisés. Elle est fermée : recommencez depuis votre assistant.",
 "Se han agotado todos los códigos de esta conexión. Está cerrada: vuelva a empezar desde su asistente.",
 "Alle Codes dieser Verbindung sind aufgebraucht. Sie ist geschlossen: Starten Sie sie erneut über Ihren Assistenten."),
"Connect an assistant": (
 "Connecter un assistant",
 "Conectar un asistente",
 "Einen Assistenten verbinden"),
"Identified by the description it publishes at that address": (
 "Identifiée par la description qu'elle publie à cette adresse",
 "Identificada por la descripción que publica en esa dirección",
 "Identifiziert durch die Beschreibung, die sie unter dieser Adresse veröffentlicht"),
"Registered by hand in the module settings": (
 "Enregistrée à la main dans les réglages du module",
 "Registrada a mano en los ajustes del módulo",
 "Von Hand in den Moduleinstellungen eingetragen"),
"Registered itself automatically": (
 "Enregistrée automatiquement par elle-même",
 "Registrada automáticamente por sí misma",
 "Hat sich selbst automatisch registriert"),
"This shop is not accepting connections from assistants right now.": (
 "Cette boutique n'accepte pas de connexion d'assistant pour le moment.",
 "Esta tienda no acepta conexiones de asistentes en este momento.",
 "Dieser Shop nimmt derzeit keine Verbindungen von Assistenten an."),
"This connection request is not valid, so nothing was sent back to the application that made it.": (
 "Cette demande de connexion n'est pas valide : rien n'a été renvoyé à l'application qui l'a émise.",
 "Esta solicitud de conexión no es válida, por lo que no se ha devuelto nada a la aplicación que la hizo.",
 "Diese Verbindungsanfrage ist ungültig, daher wurde nichts an die anfragende Anwendung zurückgesendet."),
"Connect to this shop, see the list of available tools, and read your account details and the shop configuration": (
 "Se connecter à cette boutique, voir la liste des outils disponibles, et lire les informations de votre compte ainsi que la configuration de la boutique",
 "Conectarse a esta tienda, ver la lista de herramientas disponibles y leer los datos de su cuenta y la configuración de la tienda",
 "Sich mit diesem Shop verbinden, die Liste der verfügbaren Werkzeuge sehen und die Daten Ihres Kontos sowie die Shop-Konfiguration lesen"),
"Read the orders of this shop, and who placed them": (
 "Lire les commandes de cette boutique, et savoir qui les a passées",
 "Leer los pedidos de esta tienda y saber quién los realizó",
 "Die Bestellungen dieses Shops lesen und sehen, wer sie aufgegeben hat"),
"Change the orders of this shop, which can email your customers": (
 "Modifier les commandes de cette boutique, ce qui peut envoyer des e-mails à vos clients",
 "Modificar los pedidos de esta tienda, lo que puede enviar correos electrónicos a sus clientes",
 "Die Bestellungen dieses Shops ändern, wodurch E-Mails an Ihre Kunden gesendet werden können"),
"Read the catalogue of this shop: products, prices and categories": (
 "Lire le catalogue de cette boutique : produits, prix et catégories",
 "Leer el catálogo de esta tienda: productos, precios y categorías",
 "Den Katalog dieses Shops lesen: Produkte, Preise und Kategorien"),
"Change the catalogue of this shop: create, edit and delete products, create and edit categories, create and edit brands, create, edit and delete their addresses, create and edit suppliers, create and edit attribute groups, create and edit attribute values, create and edit features and their values, change prices and stock": (
 "Modifier le catalogue de cette boutique : créer, modifier et supprimer les produits, créer et modifier les catégories, créer et modifier les marques, créer, modifier et supprimer leurs adresses, créer et modifier les fournisseurs, créer et modifier les groupes d'attributs, créer et modifier les valeurs d'attributs, créer et modifier les caractéristiques et leurs valeurs, modifier les prix et le stock",
 "Modificar el catálogo de esta tienda: crear, editar y eliminar productos, crear y editar categorías, crear y editar marcas, crear, editar y eliminar sus direcciones, crear y editar proveedores, crear y editar grupos de atributos, crear y editar valores de atributos, crear y editar características y sus valores, cambiar precios y stock",
 "Den Katalog dieses Shops ändern: Produkte anlegen, bearbeiten und löschen, Kategorien anlegen und bearbeiten, Marken anlegen und bearbeiten, ihre Adressen anlegen, bearbeiten und löschen, Lieferanten anlegen und bearbeiten, Attributgruppen anlegen und bearbeiten, Attributwerte anlegen und bearbeiten, Merkmale und ihre Werte anlegen und bearbeiten, Preise und Bestand ändern"),
"Read the stock of this shop: quantities of products and combinations": (
 "Lire le stock de cette boutique : quantités des produits et des déclinaisons",
 "Leer el stock de esta tienda: cantidades de los productos y de las combinaciones",
 "Den Bestand dieses Shops lesen: Mengen der Produkte und Kombinationen"),
"Change the stock of this shop: set the quantities of products and combinations, recorded as stock movements": (
 "Modifier le stock de cette boutique : régler les quantités des produits et des déclinaisons, enregistrées comme mouvements de stock",
 "Modificar el stock de esta tienda: ajustar las cantidades de los productos y de las combinaciones, registradas como movimientos de stock",
 "Den Bestand dieses Shops ändern: Mengen der Produkte und Kombinationen festlegen, als Lagerbewegungen erfasst"),
"Read the customers of this shop: names, email addresses, groups and what they spent": (
 "Lire les clients de cette boutique : noms, adresses e-mail, groupes et montant de leurs achats",
 "Leer los clientes de esta tienda: nombres, direcciones de correo electrónico, grupos y lo que han gastado",
 "Die Kunden dieses Shops lesen: Namen, E-Mail-Adressen, Gruppen und ihre Umsätze"),
"Outgoing HTTP requests": (
 "Requêtes HTTP sortantes",
 "Peticiones HTTP salientes",
 "Ausgehende HTTP-Anfragen"),
"The cURL extension is available.": (
 "L'extension cURL est disponible.",
 "La extensión cURL está disponible.",
 "Die cURL-Erweiterung ist verfügbar."),
"The cURL extension is missing. The module cannot read the metadata document published by a client, so no assistant can connect.": (
 "L'extension cURL est absente. Le module ne peut pas lire le document de description publié par une application, donc aucun assistant ne peut se connecter.",
 "Falta la extensión cURL. El módulo no puede leer el documento de descripción publicado por una aplicación, así que ningún asistente puede conectarse.",
 "Die cURL-Erweiterung fehlt. Das Modul kann das von einer Anwendung veröffentlichte Beschreibungsdokument nicht lesen, daher kann sich kein Assistent verbinden."),
"Friendly URLs": (
 "URL simplifiées",
 "URL amigables",
 "Suchmaschinenfreundliche URLs"),
"Friendly URLs are enabled.": (
 "Les URL simplifiées sont activées.",
 "Las URL amigables están activadas.",
 "Suchmaschinenfreundliche URLs sind aktiviert."),
"Friendly URLs are disabled. PrestaShop then routes nothing outside index.php, so the discovery documents cannot be reached and no assistant can connect. Enable them in Shop parameters, Traffic and SEO.": (
 "Les URL simplifiées sont désactivées. PrestaShop ne route alors rien en dehors d'index.php : les documents de découverte sont injoignables et aucun assistant ne peut se connecter. Activez-les dans Paramètres de la boutique, Trafic et SEO.",
 "Las URL amigables están desactivadas. PrestaShop no enruta entonces nada fuera de index.php: los documentos de descubrimiento no son accesibles y ningún asistente puede conectarse. Actívelas en Parámetros de la tienda, Tráfico y SEO.",
 "Suchmaschinenfreundliche URLs sind deaktiviert. PrestaShop leitet dann nichts außerhalb von index.php weiter: Die Discovery-Dokumente sind nicht erreichbar und kein Assistent kann sich verbinden. Aktivieren Sie sie unter Shop-Parameter, Traffic und SEO."),
"Shop location": (
 "Emplacement de la boutique",
 "Ubicación de la tienda",
 "Speicherort des Shops"),
"This shop is installed in a subdirectory. The authorization server document has to sit at the root of the domain, which is outside the shop, so PrestaShop cannot serve it. Moving the shop to the root of a domain or a subdomain is the only way around it.": (
 "Cette boutique est installée dans un sous-répertoire. Le document du serveur d'autorisation doit se trouver à la racine du domaine, donc en dehors de la boutique : PrestaShop ne peut pas le servir. Déplacer la boutique à la racine d'un domaine ou d'un sous-domaine est la seule solution.",
 "Esta tienda está instalada en un subdirectorio. El documento del servidor de autorización debe estar en la raíz del dominio, es decir, fuera de la tienda, por lo que PrestaShop no puede servirlo. Mover la tienda a la raíz de un dominio o subdominio es la única solución.",
 "Dieser Shop ist in einem Unterverzeichnis installiert. Das Dokument des Autorisierungsservers muss im Stammverzeichnis der Domain liegen, also außerhalb des Shops, sodass PrestaShop es nicht ausliefern kann. Der Shop muss dafür in das Stammverzeichnis einer Domain oder Subdomain umziehen."),
"The shop sits at the root of its domain.": (
 "La boutique se trouve à la racine de son domaine.",
 "La tienda está en la raíz de su dominio.",
 "Der Shop liegt im Stammverzeichnis seiner Domain."),
"HTTPS certificate": (
 "Certificat HTTPS",
 "Certificado HTTPS",
 "HTTPS-Zertifikat"),
"The certificate is valid.": (
 "Le certificat est valide.",
 "El certificado es válido.",
 "Das Zertifikat ist gültig."),
"This shop runs on a local address, which assistants cannot reach. To test it, give it a public HTTPS address with a tunnel such as cloudflared or ngrok, and set the shop domain to it.": (
 "Cette boutique tourne sur une adresse locale, que les assistants ne peuvent pas joindre. Pour la tester, donnez-lui une adresse HTTPS publique avec un tunnel comme cloudflared ou ngrok, et réglez le domaine de la boutique sur cette adresse.",
 "Esta tienda funciona en una dirección local, que los asistentes no pueden alcanzar. Para probarla, dele una dirección HTTPS pública con un túnel como cloudflared o ngrok, y configure el dominio de la tienda con ella.",
 "Dieser Shop läuft unter einer lokalen Adresse, die Assistenten nicht erreichen können. Um ihn zu testen, geben Sie ihm mit einem Tunnel wie cloudflared oder ngrok eine öffentliche HTTPS-Adresse und stellen Sie die Shop-Domain darauf ein."),
"The certificate is refused: %reason%. Assistants only connect to a shop whose certificate is valid. Install or renew it from your hosting panel.": (
 "Le certificat est refusé : %reason%. Les assistants ne se connectent qu'à une boutique dont le certificat est valide. Installez-le ou renouvelez-le depuis le panneau de votre hébergeur.",
 "El certificado es rechazado: %reason%. Los asistentes solo se conectan a una tienda cuyo certificado es válido. Instálelo o renuévelo desde el panel de su alojamiento.",
 "Das Zertifikat wird abgelehnt: %reason%. Assistenten verbinden sich nur mit einem Shop, dessen Zertifikat gültig ist. Installieren oder erneuern Sie es in der Verwaltung Ihres Hosters."),
"Nothing answers over HTTPS on this domain. Assistants only connect over HTTPS: enable a certificate for it from your hosting panel.": (
 "Rien ne répond en HTTPS sur ce domaine. Les assistants ne se connectent qu'en HTTPS : activez un certificat pour ce domaine depuis le panneau de votre hébergeur.",
 "Nada responde por HTTPS en este dominio. Los asistentes solo se conectan por HTTPS: active un certificado para él desde el panel de su alojamiento.",
 "Unter dieser Domain antwortet nichts über HTTPS. Assistenten verbinden sich nur über HTTPS: Aktivieren Sie in der Verwaltung Ihres Hosters ein Zertifikat für diese Domain."),
"The shop could not be reached over HTTPS: %reason%.": (
 "La boutique n'a pas pu être jointe en HTTPS : %reason%.",
 "No se ha podido acceder a la tienda por HTTPS: %reason%.",
 "Der Shop war über HTTPS nicht erreichbar: %reason%."),
"SSL in PrestaShop": (
 "SSL dans PrestaShop",
 "SSL en PrestaShop",
 "SSL in PrestaShop"),
"SSL is enabled.": (
 "Le SSL est activé.",
 "El SSL está activado.",
 "SSL ist aktiviert."),
"SSL is disabled, which is fine on a local shop. Enable it before the shop goes online.": (
 "Le SSL est désactivé, ce qui convient pour une boutique locale. Activez-le avant la mise en ligne.",
 "El SSL está desactivado, lo que es aceptable en una tienda local. Actívelo antes de poner la tienda en línea.",
 "SSL ist deaktiviert, was für einen lokalen Shop in Ordnung ist. Aktivieren Sie es, bevor der Shop online geht."),
"SSL is disabled. The shop and its back office answer over plain HTTP, and employees sign in unencrypted. Enable it in Shop parameters, General.": (
 "Le SSL est désactivé. La boutique et son back-office répondent en HTTP simple, et les employés se connectent sans chiffrement. Activez-le dans Paramètres de la boutique, Général.",
 "El SSL está desactivado. La tienda y su back office responden por HTTP sin cifrar, y los empleados inician sesión sin cifrado. Actívelo en Parámetros de la tienda, General.",
 "SSL ist deaktiviert. Der Shop und sein Backoffice antworten über unverschlüsseltes HTTP, und Mitarbeiter melden sich unverschlüsselt an. Aktivieren Sie es unter Shop-Parameter, Allgemein."),
"This address answered %status% instead of 200. Assistants read it to find out where to sign in, so none of them can connect until it works.": (
 "Cette adresse a répondu %status% au lieu de 200. Les assistants la lisent pour savoir où se connecter : aucun ne peut se connecter tant qu'elle ne fonctionne pas.",
 "Esta dirección respondió %status% en lugar de 200. Los asistentes la leen para saber dónde iniciar sesión: ninguno puede conectarse hasta que funcione.",
 "Diese Adresse hat mit %status% statt 200 geantwortet. Assistenten lesen sie, um zu erfahren, wo sie sich anmelden: Keiner kann sich verbinden, solange sie nicht funktioniert."),
"This address could not be reached: %reason%.": (
 "Cette adresse n'a pas pu être jointe : %reason%.",
 "No se ha podido acceder a esta dirección: %reason%.",
 "Diese Adresse war nicht erreichbar: %reason%."),
"The maintenance page answered instead of the module: this address does not reach it.": (
 "La page de maintenance a répondu à la place du module : cette adresse ne l'atteint pas.",
 "La página de mantenimiento respondió en lugar del módulo: esta dirección no llega hasta él.",
 "Die Wartungsseite hat statt des Moduls geantwortet: Diese Adresse erreicht es nicht."),
"Not checked: fix %causes% above first.": (
 "Non vérifié : corrigez d'abord %causes% ci-dessus.",
 "No comprobado: corrija primero %causes% más arriba.",
 "Nicht geprüft: Beheben Sie zuerst %causes% oben."),
"Go to the setting": (
 "Aller au réglage",
 "Ir al ajuste",
 "Zur Einstellung"),
"The administration folder is not known yet. Save the settings once and this resolves itself.": (
 "Le dossier d'administration n'est pas encore connu. Enregistrez les réglages une fois et le problème disparaît.",
 "La carpeta de administración aún no se conoce. Guarde los ajustes una vez y esto se resuelve solo.",
 "Der Verwaltungsordner ist noch nicht bekannt. Speichern Sie die Einstellungen einmal, dann erledigt sich das von selbst."),
"Authorization server document": (
 "Document du serveur d'autorisation",
 "Documento del servidor de autorización",
 "Dokument des Autorisierungsservers"),
"This address answered %status% instead of 200. Assistants read it to find out where to sign in, so none of them can connect until it works. Some hosting setups answer paths starting with .well-known themselves, before PrestaShop ever sees them; a rewrite rule sending that path to index.php fixes it.": (
 "Cette adresse a répondu %status% au lieu de 200. Les assistants la lisent pour savoir où se connecter : aucun ne pourra se connecter tant qu'elle ne fonctionne pas. Certains hébergements répondent eux-mêmes aux chemins commençant par .well-known, avant que PrestaShop ne les voie ; une règle de réécriture envoyant ce chemin vers index.php règle le problème.",
 "Esta dirección respondió %status% en lugar de 200. Los asistentes la leen para saber dónde iniciar sesión, así que ninguno podrá conectarse mientras no funcione. Algunos alojamientos responden ellos mismos a las rutas que empiezan por .well-known, antes de que PrestaShop las vea; una regla de reescritura que envíe esa ruta a index.php lo soluciona.",
 "Diese Adresse hat %status% statt 200 geantwortet. Assistenten lesen sie, um zu erfahren, wo sie sich anmelden sollen, daher kann sich keiner verbinden, solange sie nicht funktioniert. Manche Hosting-Umgebungen beantworten Pfade, die mit .well-known beginnen, selbst, bevor PrestaShop sie überhaupt sieht; eine Rewrite-Regel, die diesen Pfad an index.php weiterleitet, behebt das."),
"This address answered, but not with the expected document. Something else on the hosting is serving that path.": (
 "Cette adresse a répondu, mais pas avec le document attendu. Autre chose sur l'hébergement sert ce chemin.",
 "Esta dirección respondió, pero no con el documento esperado. Otra cosa en el alojamiento está sirviendo esa ruta.",
 "Diese Adresse hat geantwortet, aber nicht mit dem erwarteten Dokument. Etwas anderes auf dem Hosting liefert diesen Pfad aus."),
"Served correctly.": (
 "Servi correctement.",
 "Servido correctamente.",
 "Wird korrekt ausgeliefert."),
"Protected resource document": (
 "Document de la ressource protégée",
 "Documento del recurso protegido",
 "Dokument der geschützten Ressource"),
"This address answered %status% instead of the expected document.": (
 "Cette adresse a répondu %status% au lieu du document attendu.",
 "Esta dirección respondió %status% en lugar del documento esperado.",
 "Diese Adresse hat %status% statt des erwarteten Dokuments geantwortet."),
"MCP endpoint": (
 "Point d'entrée MCP",
 "Punto de entrada MCP",
 "MCP-Endpunkt"),
"The endpoint answered %status% where an unauthenticated call must answer 401.": (
 "Le point d'entrée a répondu %status% là où un appel non authentifié doit répondre 401.",
 "El punto de entrada respondió %status% donde una llamada no autenticada debe responder 401.",
 "Der Endpunkt hat %status% geantwortet, wo ein nicht authentifizierter Aufruf 401 antworten muss."),
"The endpoint answered 401 but without the challenge that tells an assistant where to sign in.": (
 "Le point d'entrée a répondu 401 mais sans l'en-tête qui indique à un assistant où se connecter.",
 "El punto de entrada respondió 401 pero sin la cabecera que indica al asistente dónde iniciar sesión.",
 "Der Endpunkt hat 401 geantwortet, aber ohne den Header, der einem Assistenten sagt, wo er sich anmelden soll."),
"The endpoint answers correctly, but the Authorization header never reached PHP: this hosting drops it. Assistants will always be treated as signed out. Adding a rewrite rule that copies the header into the environment fixes it.": (
 "Le point d'entrée répond correctement, mais l'en-tête Authorization n'est jamais arrivé jusqu'à PHP : cet hébergement le supprime. Les assistants seront toujours considérés comme déconnectés. Ajouter une règle de réécriture qui recopie l'en-tête dans l'environnement règle le problème.",
 "El punto de entrada responde correctamente, pero la cabecera Authorization nunca llegó a PHP: este alojamiento la elimina. Los asistentes siempre se considerarán desconectados. Añadir una regla de reescritura que copie la cabecera al entorno lo soluciona.",
 "Der Endpunkt antwortet korrekt, aber der Authorization-Header hat PHP nie erreicht: Dieses Hosting entfernt ihn. Assistenten gelten dadurch immer als abgemeldet. Eine Rewrite-Regel, die den Header in die Umgebung kopiert, behebt das."),
"Answers with the expected challenge, and credentials reach the module.": (
 "Répond avec l'en-tête attendu, et les identifiants arrivent bien jusqu'au module.",
 "Responde con la cabecera esperada, y las credenciales llegan al módulo.",
 "Antwortet mit dem erwarteten Header, und die Anmeldedaten erreichen das Modul."),
"Sign-in page": (
 "Page de connexion",
 "Página de inicio de sesión",
 "Anmeldeseite"),
"This page sends visitors elsewhere (%location%) instead of showing the sign-in form. Another module or a rule of the hosting redirects it, so no employee can approve an assistant. Exclude this address from that redirection.": (
 "Cette page envoie les visiteurs ailleurs (%location%) au lieu d'afficher le formulaire de connexion. Un autre module ou une règle de l'hébergement la redirige : aucun employé ne peut donc autoriser un assistant. Excluez cette adresse de cette redirection.",
 "Esta página envía a los visitantes a otro sitio (%location%) en lugar de mostrar el formulario de inicio de sesión. Otro módulo o una regla del alojamiento la redirige, así que ningún empleado puede autorizar a un asistente. Excluya esta dirección de esa redirección.",
 "Diese Seite schickt Besucher woandershin (%location%), statt das Anmeldeformular anzuzeigen. Ein anderes Modul oder eine Regel des Hostings leitet sie um, sodass kein Mitarbeiter einen Assistenten freigeben kann. Nehmen Sie diese Adresse von dieser Umleitung aus."),
"This page answered %status%, but not with the module's sign-in form. Something else on the shop answers this address in its place, so no employee can approve an assistant.": (
 "Cette page a répondu %status%, mais pas avec le formulaire de connexion du module. Autre chose sur la boutique répond à sa place à cette adresse : aucun employé ne peut donc autoriser un assistant.",
 "Esta página respondió %status%, pero no con el formulario de inicio de sesión del módulo. Otra cosa en la tienda responde en su lugar a esta dirección, así que ningún empleado puede autorizar a un asistente.",
 "Diese Seite hat %status% geantwortet, aber nicht mit dem Anmeldeformular des Moduls. Etwas anderes im Shop beantwortet diese Adresse an seiner Stelle, sodass kein Mitarbeiter einen Assistenten freigeben kann."),
"Shows the module's sign-in form.": (
 "Affiche le formulaire de connexion du module.",
 "Muestra el formulario de inicio de sesión del módulo.",
 "Zeigt das Anmeldeformular des Moduls an."),
"An application is asking to connect to your shop on your behalf.": (
 "Une application demande à se connecter à votre boutique en votre nom.",
 "Una aplicación solicita conectarse a su tienda en su nombre.",
 "Eine Anwendung möchte sich in Ihrem Namen mit Ihrem Shop verbinden."),
"Application": (
 "Application",
 "Aplicación",
 "Anwendung"),
"Identifier": (
 "Identifiant",
 "Identificador",
 "Kennung"),
"Signed in as": (
 "Connecté en tant que",
 "Conectado como",
 "Angemeldet als"),
"Shop": (
 "Boutique",
 "Tienda",
 "Shop"),
"Will be sent back to": (
 "Sera renvoyé vers",
 "Será redirigido a",
 "Wird zurückgeleitet an"),
"What it will be able to do": (
 "Ce qu'elle pourra faire",
 "Lo que podrá hacer",
 "Was sie tun können wird"),
"The application never gets more rights than your own account. You can disconnect it at any time from the module settings.": (
 "L'application n'obtient jamais plus de droits que votre propre compte. Vous pouvez la déconnecter à tout moment depuis les réglages du module.",
 "La aplicación nunca obtiene más permisos que su propia cuenta. Puede desconectarla en cualquier momento desde los ajustes del módulo.",
 "Die Anwendung erhält nie mehr Rechte als Ihr eigenes Konto. Sie können sie jederzeit in den Moduleinstellungen trennen."),
"Refuse": (
 "Refuser",
 "Rechazar",
 "Ablehnen"),
"Allow the connection": (
 "Autoriser la connexion",
 "Permitir la conexión",
 "Verbindung erlauben"),
"Address to give to your assistant": (
 "Adresse à donner à votre assistant",
 "Dirección que debe dar a su asistente",
 "Adresse für Ihren Assistenten"),
"Paste this address into Claude, ChatGPT, Le Chat or any other MCP-compatible assistant. It will ask you to sign in to this shop and to approve the connection.": (
 "Collez cette adresse dans Claude, ChatGPT, Le Chat ou tout autre assistant compatible MCP. Il vous demandera de vous connecter à cette boutique et d'autoriser la connexion.",
 "Pegue esta dirección en Claude, ChatGPT, Le Chat o cualquier otro asistente compatible con MCP. Le pedirá que inicie sesión en esta tienda y que autorice la conexión.",
 "Fügen Sie diese Adresse in Claude, ChatGPT, Le Chat oder einen anderen MCP-kompatiblen Assistenten ein. Er wird Sie bitten, sich in diesem Shop anzumelden und die Verbindung freizugeben."),
"Authorization server:": (
 "Serveur d'autorisation :",
 "Servidor de autorización:",
 "Autorisierungsserver:"),
"current shop": (
 "boutique courante",
 "tienda actual",
 "aktueller Shop"),
"Each shop has its own address and its own connections. An assistant connected to one shop reaches that shop only.": (
 "Chaque boutique a sa propre adresse et ses propres connexions. Un assistant connecté à une boutique n'atteint que celle-là.",
 "Cada tienda tiene su propia dirección y sus propias conexiones. Un asistente conectado a una tienda solo alcanza esa.",
 "Jeder Shop hat seine eigene Adresse und seine eigenen Verbindungen. Ein mit einem Shop verbundener Assistent erreicht nur diesen."),
"Diagnostic": (
 "Diagnostic",
 "Diagnóstico",
 "Diagnose"),
"Shop enabled": (
 "Boutique activée",
 "Tienda activada",
 "Shop aktiviert"),
"This shop is enabled.": (
 "Cette boutique est activée.",
 "Esta tienda está activada.",
 "Dieser Shop ist aktiviert."),
"This shop is disabled in the multistore settings. It serves nothing at all, so no assistant can reach it. Enable it to use the MCP server on this shop.": (
 "Cette boutique est désactivée dans les réglages multiboutique. Elle ne sert rien du tout, donc aucun assistant ne peut l'atteindre. Activez-la pour utiliser le serveur MCP sur cette boutique.",
 "Esta tienda está desactivada en los ajustes multitienda. No sirve nada en absoluto, así que ningún asistente puede alcanzarla. Actívela para usar el servidor MCP en esta tienda.",
 "Dieser Shop ist in den Multishop-Einstellungen deaktiviert. Er liefert überhaupt nichts aus, daher kann ihn kein Assistent erreichen. Aktivieren Sie ihn, um den MCP-Server fuer diesen Shop zu nutzen."),
"Shop open": (
 "Boutique ouverte",
 "Tienda abierta",
 "Shop geöffnet"),
"This shop is open.": (
 "Cette boutique est ouverte.",
 "Esta tienda está abierta.",
 "Dieser Shop ist geöffnet."),
"This shop is in maintenance. Assistants still connect and work: the MCP address belongs to you, not to your visitors. Turn off the MCP server above if you want it closed too.": (
 "Cette boutique est en maintenance. Les assistants se connectent et fonctionnent quand même : l'adresse MCP est la vôtre, pas celle de vos visiteurs. Désactivez le serveur MCP ci-dessus si vous voulez le fermer aussi.",
 "Esta tienda está en mantenimiento. Los asistentes se conectan y funcionan igualmente: la dirección MCP es suya, no de sus visitantes. Desactive el servidor MCP arriba si quiere cerrarlo también.",
 "Dieser Shop ist im Wartungsmodus. Assistenten verbinden sich und arbeiten weiterhin: Die MCP-Adresse gehört Ihnen, nicht Ihren Besuchern. Schalten Sie den MCP-Server oben ab, wenn Sie ihn ebenfalls schließen möchten."),
"Checks one shop the way an assistant would: it calls that shop own addresses from the outside and reports what comes back.": (
 "Contrôle une boutique comme le ferait un assistant : les adresses propres à cette boutique sont appelées depuis l'extérieur et le résultat est affiché.",
 "Comprueba una tienda como lo haría un asistente: llama a las direcciones propias de esa tienda desde fuera y muestra lo que responden.",
 "Prüft einen Shop so, wie es ein Assistent tun würde: Die eigenen Adressen dieses Shops werden von außen aufgerufen und das Ergebnis wird angezeigt."),
"Run the diagnostic": (
 "Lancer le diagnostic",
 "Ejecutar el diagnóstico",
 "Diagnose starten"),
"Settings reserved for the shop administrator": (
 "Réglages réservés à l'administrateur de la boutique",
 "Ajustes reservados al administrador de la tienda",
 "Einstellungen dem Shop-Administrator vorbehalten"),
"Only an administrator of this shop can change the O'So MCP Server settings.": (
 "Les réglages d'O'So MCP Server sont modifiables uniquement par un administrateur de la boutique.",
 "Los ajustes de O'So MCP Server solo puede modificarlos un administrador de la tienda.",
 "Die Einstellungen von O'So MCP Server kann nur ein Administrator des Shops ändern."),
"Only an administrator of this shop can change the O'So MCP Server settings, and your account is not allowed to connect an assistant. Ask an administrator if you need one.": (
 "Les réglages d'O'So MCP Server sont modifiables uniquement par un administrateur de la boutique, et votre compte n'est pas autorisé à connecter un assistant. Demandez à un administrateur si vous en avez besoin.",
 "Los ajustes de O'So MCP Server solo puede modificarlos un administrador de la tienda, y su cuenta no está autorizada a conectar un asistente. Pídaselo a un administrador si lo necesita.",
 "Die Einstellungen von O'So MCP Server kann nur ein Administrator des Shops ändern, und Ihr Konto darf keinen Assistenten verbinden. Wenden Sie sich an einen Administrator, wenn Sie einen benötigen."),
"Copy": (
 "Copier",
 "Copiar",
 "Kopieren"),
"Copied": (
 "Copié",
 "Copiado",
 "Kopiert"),
"Your account may connect an assistant. Paste this address into it, then approve the connection when the shop asks you to sign in.": (
 "Votre compte peut connecter un assistant. Collez cette adresse dedans, puis autorisez la connexion quand la boutique vous demandera de vous identifier.",
 "Su cuenta puede conectar un asistente. Pegue esta dirección en él y autorice la conexión cuando la tienda le pida identificarse.",
 "Ihr Konto darf einen Assistenten verbinden. Fügen Sie diese Adresse dort ein und geben Sie die Verbindung frei, wenn der Shop Sie zur Anmeldung auffordert."),
"Settings": (
 "Réglages",
 "Ajustes",
 "Einstellungen"),
"MCP server": (
 "Serveur MCP",
 "Servidor MCP",
 "MCP-Server"),
"Enable the MCP server": (
 "Activer le serveur MCP",
 "Activar el servidor MCP",
 "MCP-Server aktivieren"),
"Yes": (
 "Oui",
 "Sí",
 "Ja"),
"No": (
 "Non",
 "No",
 "Nein"),
"Turning this off closes the endpoint immediately. Tokens already issued stop working.": (
 "Désactiver ferme le point d'entrée immédiatement. Les jetons déjà émis cessent de fonctionner.",
 "Desactivarlo cierra el punto de entrada de inmediato. Los tokens ya emitidos dejan de funcionar.",
 "Das Deaktivieren schließt den Endpunkt sofort. Bereits ausgegebene Token funktionieren nicht mehr."),
"Employees allowed to connect an assistant": (
 "Employés autorisés à connecter un assistant",
 "Empleados autorizados a conectar un asistente",
 "Mitarbeiter, die einen Assistenten verbinden dürfen"),
"Any employee": (
 "Tous les employés",
 "Cualquier empleado",
 "Alle Mitarbeiter"),
"An assistant never gets more rights than the employee who connected it.": (
 "Un assistant n'obtient jamais plus de droits que l'employé qui l'a connecté.",
 "Un asistente nunca obtiene más permisos que el empleado que lo conectó.",
 "Ein Assistent erhält nie mehr Rechte als der Mitarbeiter, der ihn verbunden hat."),
"OAuth 2.1": (
 "OAuth 2.1",
 "OAuth 2.1",
 "OAuth 2.1"),
"Access token lifetime (seconds)": (
 "Durée de vie du jeton d'accès (secondes)",
 "Duración del token de acceso (segundos)",
 "Gültigkeitsdauer des Zugriffstokens (Sekunden)"),
"Refresh token lifetime (seconds)": (
 "Durée de vie du jeton de renouvellement (secondes)",
 "Duración del token de renovación (segundos)",
 "Gültigkeitsdauer des Erneuerungstokens (Sekunden)"),
"How long an assistant can keep reconnecting on its own before the merchant has to approve it again.": (
 "Durée pendant laquelle un assistant peut se reconnecter tout seul avant que le marchand doive l'autoriser à nouveau.",
 "Tiempo durante el cual un asistente puede reconectarse solo antes de que el comerciante deba autorizarlo de nuevo.",
 "Zeitraum, in dem sich ein Assistent selbstständig wieder verbinden kann, bevor der Händler ihn erneut freigeben muss."),
"Replace the refresh token on every use": (
 "Remplacer le jeton de renouvellement à chaque utilisation",
 "Sustituir el token de renovación en cada uso",
 "Erneuerungstoken bei jeder Nutzung ersetzen"),
"Recommended. A token that is used twice then reveals a theft, and the shop cuts the whole connection.": (
 "Recommandé. Un jeton utilisé deux fois révèle alors un vol, et la boutique coupe toute la connexion.",
 "Recomendado. Un token usado dos veces revela entonces un robo, y la tienda corta toda la conexión.",
 "Empfohlen. Ein zweimal verwendetes Token verrät dann einen Diebstahl, und der Shop trennt die gesamte Verbindung."),
"Value of its own here": (
 "Valeur propre à ce niveau",
 "Valor propio en este nivel",
 "Eigener Wert auf dieser Ebene"),
"inherited from the group": (
 "héritée du groupe",
 "heredado del grupo",
 "von der Gruppe geerbt"),
"inherited from all shops": (
 "héritée de toutes les boutiques",
 "heredado de todas las tiendas",
 "von allen Shops geerbt"),
"module default": (
 "valeur par défaut du module",
 "valor por defecto del módulo",
 "Standardwert des Moduls"),
"Security": (
 "Sécurité",
 "Seguridad",
 "Sicherheit"),
"Let applications identify themselves by address": (
 "Laisser les applications s'identifier par leur adresse",
 "Permitir que las aplicaciones se identifiquen por su dirección",
 "Anwendungen erlauben, sich über ihre Adresse auszuweisen"),
"This is how the Claude assistants connect. Turning it off will stop them from working.": (
 "C'est ainsi que les assistants Claude se connectent. Désactiver les empêchera de fonctionner.",
 "Así es como se conectan los asistentes Claude. Desactivarlo impedirá que funcionen.",
 "So verbinden sich die Claude-Assistenten. Ein Deaktivieren verhindert, dass sie funktionieren."),
"Let applications register themselves": (
 "Laisser les applications s'enregistrer elles-mêmes",
 "Permitir que las aplicaciones se registren por sí mismas",
 "Anwendungen erlauben, sich selbst zu registrieren"),
"Older way of connecting, kept for assistants that know nothing else. Registering costs nothing on its own: an application still has to be approved by an employee before it can do anything.": (
 "Ancienne manière de se connecter, conservée pour les assistants qui ne connaissent que celle-là. S'enregistrer ne donne rien en soi : une application doit toujours être autorisée par un employé avant de pouvoir agir.",
 "Forma antigua de conectarse, mantenida para los asistentes que solo conocen esa. Registrarse no da nada por sí mismo: una aplicación todavía debe ser autorizada por un empleado antes de poder actuar.",
 "Ältere Art der Verbindung, beibehalten für Assistenten, die nichts anderes kennen. Die Registrierung allein bewirkt nichts: Eine Anwendung muss weiterhin von einem Mitarbeiter freigegeben werden, bevor sie etwas tun kann."),
"Only allow these return addresses": (
 "N'autoriser que ces adresses de retour",
 "Permitir solo estas direcciones de retorno",
 "Nur diese Rückleitungsadressen zulassen"),
"Type a host name, for example claude.ai, and press Enter. Only these hosts can receive a connection, and an empty list refuses them all. Desktop assistants and coding agents use your own computer and are always allowed; a self-hosted n8n needs its own host name added.": (
 "Saisissez un nom de domaine, par exemple claude.ai, puis appuyez sur Entrée. Seuls ces domaines peuvent recevoir une connexion, et une liste vide les refuse tous. Les assistants de bureau et les agents de code passent par votre propre ordinateur et sont toujours autorisés ; un n8n auto-hébergé demande d'ajouter son propre nom de domaine.",
 "Escriba un nombre de dominio, por ejemplo claude.ai, y pulse Intro. Solo estos dominios pueden recibir una conexión, y una lista vacía los rechaza todos. Los asistentes de escritorio y los agentes de código usan su propio ordenador y siempre están permitidos; un n8n autoalojado necesita que se añada su propio nombre de dominio.",
 "Geben Sie einen Hostnamen ein, zum Beispiel claude.ai, und drücken Sie Enter. Nur diese Hosts können eine Verbindung erhalten, und eine leere Liste lehnt alle ab. Desktop-Assistenten und Coding-Agenten laufen über Ihren eigenen Rechner und sind immer erlaubt; für ein selbst gehostetes n8n muss sein eigener Hostname hinzugefügt werden."),
"After the update, reconnect your assistants so that their access includes the new permissions.": (
 "Après la mise à jour, reconnectez vos assistants pour que leur accès inclue les nouvelles permissions.",
 "Después de la actualización, vuelva a conectar sus asistentes para que su acceso incluya los nuevos permisos.",
 "Verbinden Sie Ihre Assistenten nach dem Update erneut, damit ihr Zugriff die neuen Berechtigungen umfasst."),
"Support us": ("Nous soutenir", "Apóyenos", "Unterstützen Sie uns"),
"Thank you for using O'So MCP Server": (
 "Merci d'utiliser O'So MCP Server",
 "Gracias por usar O'So MCP Server",
 "Danke, dass Sie O'So MCP Server verwenden"),
"The module is free and open source. Your support funds new tools, tests on every PrestaShop version and updates.": (
 "Le module est gratuit et open source. Votre soutien finance les nouveaux outils, les tests sur chaque version de PrestaShop et les mises à jour.",
 "El módulo es gratuito y de código abierto. Su apoyo financia las nuevas herramientas, las pruebas en cada versión de PrestaShop y las actualizaciones.",
 "Das Modul ist kostenlos und Open Source. Ihre Unterstützung finanziert neue Werkzeuge, Tests auf jeder PrestaShop-Version und Updates."),
"A monthly or one-time donation, of the amount you choose, on GitHub Sponsors.": (
 "Un don mensuel ou ponctuel, du montant de votre choix, sur GitHub Sponsors.",
 "Una donación mensual o puntual, del importe que usted elija, en GitHub Sponsors.",
 "Eine monatliche oder einmalige Spende in der Höhe Ihrer Wahl, über GitHub Sponsors."),
"Later": ("Plus tard", "Más tarde", "Später"),
"Become a sponsor on GitHub": ("Devenir sponsor sur GitHub", "Hacerse patrocinador en GitHub", "Sponsor auf GitHub werden"),
"Add a host name": ("Ajouter un nom de domaine", "Añadir un nombre de dominio", "Hostnamen hinzufügen"),
"Clients the module works with:": ("Clients compatibles avec le module :", "Clientes compatibles con el módulo:", "Mit dem Modul kompatible Clients:"),
"Keep a security log": (
 "Tenir un journal de sécurité",
 "Mantener un registro de seguridad",
 "Ein Sicherheitsprotokoll führen"),
"Connections, approvals and refusals, kept for thirty days. Never contains a token.": (
 "Connexions, autorisations et refus, conservés trente jours. Ne contient jamais de jeton.",
 "Conexiones, autorizaciones y rechazos, conservados durante treinta días. Nunca contiene un token.",
 "Verbindungen, Freigaben und Ablehnungen, dreißig Tage lang aufbewahrt. Enthält nie ein Token."),
"Save": (
 "Enregistrer",
 "Guardar",
 "Speichern"),
"Connected assistants": (
 "Assistants connectés",
 "Asistentes conectados",
 "Verbundene Assistenten"),
"Approved by": (
 "Autorisé par",
 "Autorizado por",
 "Freigegeben von"),
"Profile": (
 "Profil",
 "Perfil",
 "Profil"),
"account disabled": (
 "compte désactivé",
 "cuenta desactivada",
 "Konto deaktiviert"),
"unknown": (
 "inconnu",
 "desconocido",
 "unbekannt"),
"Permissions": (
 "Permissions",
 "Permisos",
 "Berechtigungen"),
"Since": (
 "Depuis",
 "Desde",
 "Seit"),
"Disconnect": (
 "Déconnecter",
 "Desconectar",
 "Trennen"),
"No assistant is connected to this shop yet.": (
 "Aucun assistant n'est encore connecté à cette boutique.",
 "Todavía no hay ningún asistente conectado a esta tienda.",
 "Es ist noch kein Assistent mit diesem Shop verbunden."),
"Security log": (
 "Journal de sécurité",
 "Registro de seguridad",
 "Sicherheitsprotokoll"),
"Date": (
 "Date",
 "Fecha",
 "Datum"),
"Event": (
 "Événement",
 "Evento",
 "Ereignis"),
"Detail": (
 "Détail",
 "Detalle",
 "Detail"),
"Empty the log": (
 "Vider le journal",
 "Vaciar el registro",
 "Protokoll leeren"),
"Nothing logged yet.": (
 "Rien n'a encore été journalisé.",
 "Todavía no se ha registrado nada.",
 "Es wurde noch nichts protokolliert."),
"Available tools": (
 "Outils disponibles",
 "Herramientas disponibles",
 "Verfügbare Werkzeuge"),
"What an assistant may ask this shop to do, grouped by the permission an assistant is given for it. Choose, for a whole group or for each tool, whether it is offered to the assistants connected over MCP, to the built-in assistant, or to both. A group switched off holds back every tool in it, including those an update adds later, and switching it back on gives each tool its own switch again. A tool switched off in a column is offered to nobody there, whatever permissions their account holds. Tools published by another module carry that module name in front of theirs.": (
 "Ce qu'un assistant peut demander à cette boutique, regroupé selon l'autorisation qu'un assistant reçoit pour cela. Choisissez, pour un groupe entier ou pour chaque outil, s'il est proposé aux assistants connectés en MCP, à l'assistant intégré, ou aux deux. Un groupe désactivé retient tous ses outils, y compris ceux qu'une mise à jour ajoutera, et le réactiver rend à chaque outil son propre interrupteur. Un outil désactivé dans une colonne n'y est proposé à personne, quels que soient les droits de son compte. Les outils publiés par un autre module portent le nom de ce module devant le leur.",
 "Lo que un asistente puede pedir a esta tienda, agrupado según el permiso que recibe un asistente para ello. Elija, para un grupo entero o para cada herramienta, si se ofrece a los asistentes conectados por MCP, al asistente integrado o a ambos. Un grupo desactivado retiene todas sus herramientas, incluidas las que añada una actualización, y volver a activarlo devuelve a cada herramienta su propio interruptor. Una herramienta desactivada en una columna no se ofrece allí a nadie, sean cuales sean los permisos de su cuenta. Las herramientas publicadas por otro módulo llevan delante el nombre de ese módulo.",
 "Was ein Assistent von diesem Shop verlangen darf, gruppiert nach der Berechtigung, die ein Assistent dafür erhält. Wählen Sie für eine ganze Gruppe oder für jedes Werkzeug, ob es den über MCP verbundenen Assistenten, dem integrierten Assistenten oder beiden angeboten wird. Eine ausgeschaltete Gruppe hält alle ihre Werkzeuge zurück, auch die, die ein Update später hinzufügt, und das erneute Einschalten gibt jedem Werkzeug seinen eigenen Schalter zurück. Ein in einer Spalte ausgeschaltetes Werkzeug wird dort niemandem angeboten, unabhängig von den Rechten seines Kontos. Werkzeuge anderer Module tragen deren Modulnamen vor dem eigenen."),
"Search a tool": (
 "Rechercher un outil",
 "Buscar una herramienta",
 "Ein Werkzeug suchen"),
"Connection": (
 "Connexion",
 "Conexión",
 "Verbindung"),
"Orders - read": (
 "Commandes - lecture",
 "Pedidos - lectura",
 "Bestellungen - lesen"),
"Orders - changes": (
 "Commandes - modifications",
 "Pedidos - cambios",
 "Bestellungen - Änderungen"),
"Catalog - read": (
 "Catalogue - lecture",
 "Catálogo - lectura",
 "Katalog - lesen"),
"Catalog - changes": (
 "Catalogue - modifications",
 "Catálogo - cambios",
 "Katalog - Änderungen"),
"Stock - read": (
 "Stock - lecture",
 "Stock - lectura",
 "Lagerbestand - lesen"),
"Stock - changes": (
 "Stock - modifications",
 "Stock - cambios",
 "Lagerbestand - Änderungen"),
"Customers - read": (
 "Clients - lecture",
 "Clientes - lectura",
 "Kunden - lesen"),
"Customers - changes": (
 "Clients - modifications",
 "Clientes - cambios",
 "Kunden - Änderungen"),
"SEO - read": (
 "SEO - lecture",
 "SEO - lectura",
 "SEO - lesen"),
"SEO - changes": (
 "SEO - modifications",
 "SEO - cambios",
 "SEO - Änderungen"),
"Statistics - read": (
 "Statistiques - lecture",
 "Estadísticas - lectura",
 "Statistiken - lesen"),
"Pages - read": (
 "Pages - lecture",
 "Páginas - lectura",
 "Seiten - lesen"),
"Modules - read": (
 "Modules - lecture",
 "Módulos - lectura",
 "Module - lesen"),
"Modules - changes": (
 "Modules - modifications",
 "Módulos - cambios",
 "Module - Änderungen"),
"Design - read": (
 "Apparence - lecture",
 "Diseño - lectura",
 "Design - lesen"),
"Design - changes": (
 "Apparence - modifications",
 "Diseño - cambios",
 "Design - Änderungen"),
"Performance - read": (
 "Performances - lecture",
 "Rendimiento - lectura",
 "Leistung - lesen"),
"Performance - changes": (
 "Performances - modifications",
 "Rendimiento - cambios",
 "Leistung - Änderungen"),
"%on% of %total% on": (
 "%on% sur %total% activés",
 "%on% de %total% activados",
 "%on% von %total% an"),
"Show or hide the tools of this group": (
 "Afficher ou masquer les outils de ce groupe",
 "Mostrar u ocultar las herramientas de este grupo",
 "Die Werkzeuge dieser Gruppe ein- oder ausblenden"),
"Show or hide the prompts of this group": (
 "Afficher ou masquer les prompts de ce groupe",
 "Mostrar u ocultar los prompts de este grupo",
 "Die Prompts dieser Gruppe ein- oder ausblenden"),
"Always on": (
 "Toujours actif",
 "Siempre activo",
 "Immer an"),
"group switched off": (
 "groupe désactivé",
 "grupo desactivado",
 "Gruppe ausgeschaltet"),
"Search a prompt": (
 "Rechercher un prompt",
 "Buscar un prompt",
 "Einen Prompt suchen"),
"No tool matches this search.": (
 "Aucun outil ne correspond à cette recherche.",
 "Ninguna herramienta coincide con esta búsqueda.",
 "Kein Werkzeug entspricht dieser Suche."),
"No prompt matches this search.": (
 "Aucun prompt ne correspond à cette recherche.",
 "Ningún prompt coincide con esta búsqueda.",
 "Kein Prompt entspricht dieser Suche."),
"(filtered from %all%)": (
 "(filtré sur %all%)",
 "(filtrado de %all%)",
 "(gefiltert aus %all%)"),
"On": (
 "Actif",
 "Activo",
 "Aktiv"),
"Tool": (
 "Outil",
 "Herramienta",
 "Werkzeug"),
"Published by": (
 "Publié par",
 "Publicado por",
 "Veröffentlicht von"),
"changes data": (
 "modifie des données",
 "modifica datos",
 "ändert Daten"),
"This module": (
 "Ce module",
 "Este módulo",
 "Dieses Modul"),
"No tool is available on this shop.": (
 "Aucun outil n'est disponible sur cette boutique.",
 "No hay ninguna herramienta disponible en esta tienda.",
 "Auf diesem Shop ist kein Werkzeug verfügbar."),
"Get an order": (
 "Lire une commande",
 "Leer un pedido",
 "Eine Bestellung abrufen"),
"List order statuses": (
 "Lister les statuts de commande",
 "Listar los estados de pedido",
 "Bestellstatus auflisten"),
"Create an order status": (
 "Créer un statut de commande",
 "Crear un estado de pedido",
 "Einen Bestellstatus anlegen"),
"List customer groups": (
 "Lister les groupes de clients",
 "Listar los grupos de clientes",
 "Kundengruppen auflisten"),
"List return statuses": (
 "Lister les statuts de retour",
 "Listar los estados de devolución",
 "Rückgabestatus auflisten"),
"List carriers": (
 "Lister les transporteurs",
 "Listar los transportistas",
 "Versanddienste auflisten"),
"Create a return status": (
 "Créer un statut de retour",
 "Crear un estado de devolución",
 "Einen Rückgabestatus anlegen"),
"Change a return status": (
 "Modifier un statut de retour",
 "Modificar un estado de devolución",
 "Einen Rückgabestatus ändern"),
"Delete a return status": (
 "Supprimer un statut de retour",
 "Eliminar un estado de devolución",
 "Einen Rückgabestatus löschen"),
"Test the connection": (
 "Tester la connexion",
 "Probar la conexión",
 "Die Verbindung testen"),
"Search orders": (
 "Rechercher des commandes",
 "Buscar pedidos",
 "Bestellungen suchen"),
"List categories": (
 "Lister les catégories",
 "Listar las categorías",
 "Kategorien auflisten"),
"List brands": (
 "Lister les marques",
 "Listar las marcas",
 "Marken auflisten"),
"List brand addresses": (
 "Lister les adresses des marques",
 "Listar las direcciones de las marcas",
 "Markenadressen auflisten"),
"Get a brand address": (
 "Lire une adresse de marque",
 "Leer una dirección de marca",
 "Markenadresse lesen"),
"Add a brand address": (
 "Ajouter une adresse de marque",
 "Añadir una dirección de marca",
 "Markenadresse hinzufügen"),
"Change a brand address": (
 "Modifier une adresse de marque",
 "Modificar una dirección de marca",
 "Markenadresse ändern"),
"Delete a brand address": (
 "Supprimer une adresse de marque",
 "Eliminar una dirección de marca",
 "Markenadresse löschen"),
"Get a brand": (
 "Lire une marque",
 "Leer una marca",
 "Eine Marke abrufen"),
"Create a brand": (
 "Créer une marque",
 "Crear una marca",
 "Eine Marke anlegen"),
"Change a brand": (
 "Modifier une marque",
 "Modificar una marca",
 "Eine Marke ändern"),
"Enable or disable a brand": (
 "Activer ou désactiver une marque",
 "Activar o desactivar una marca",
 "Eine Marke aktivieren oder deaktivieren"),
"List suppliers": (
 "Lister les fournisseurs",
 "Listar los proveedores",
 "Lieferanten auflisten"),
"Get a supplier": (
 "Lire un fournisseur",
 "Leer un proveedor",
 "Einen Lieferanten abrufen"),
"Create a customer": (
 "Créer un client",
 "Crear un cliente",
 "Einen Kunden anlegen"),
"Change a customer": (
 "Modifier un client",
 "Modificar un cliente",
 "Einen Kunden ändern"),
"Change the customers of this shop: create and edit customer accounts": (
 "Modifier les clients de cette boutique : créer et modifier des comptes clients",
 "Modificar los clientes de esta tienda: crear y modificar cuentas de cliente",
 "Die Kunden dieses Shops ändern: Kundenkonten anlegen und bearbeiten"),
"Create a supplier": (
 "Créer un fournisseur",
 "Crear un proveedor",
 "Einen Lieferanten anlegen"),
"Change a supplier": (
 "Modifier un fournisseur",
 "Modificar un proveedor",
 "Einen Lieferanten ändern"),
"Enable or disable a supplier": (
 "Activer ou désactiver un fournisseur",
 "Activar o desactivar un proveedor",
 "Einen Lieferanten aktivieren oder deaktivieren"),
"List attributes": (
 "Lister les attributs",
 "Listar los atributos",
 "Attribute auflisten"),
"Get an attribute group": (
 "Lire un groupe d'attributs",
 "Leer un grupo de atributos",
 "Eine Attributgruppe abrufen"),
"Get an attribute value": (
 "Lire une valeur d'attribut",
 "Leer un valor de atributo",
 "Einen Attributwert abrufen"),
"Create an attribute group": (
 "Créer un groupe d'attributs",
 "Crear un grupo de atributos",
 "Eine Attributgruppe anlegen"),
"Change an attribute group": (
 "Modifier un groupe d'attributs",
 "Modificar un grupo de atributos",
 "Eine Attributgruppe ändern"),
"Create an attribute value": (
 "Créer une valeur d'attribut",
 "Crear un valor de atributo",
 "Einen Attributwert anlegen"),
"Change an attribute value": (
 "Modifier une valeur d'attribut",
 "Modificar un valor de atributo",
 "Einen Attributwert ändern"),
"List features": (
 "Lister les caractéristiques",
 "Listar las características",
 "Merkmale auflisten"),
"Get a feature": (
 "Lire une caractéristique",
 "Leer una característica",
 "Ein Merkmal abrufen"),
"Create a feature": (
 "Créer une caractéristique",
 "Crear una característica",
 "Ein Merkmal anlegen"),
"Change a feature": (
 "Modifier une caractéristique",
 "Modificar una característica",
 "Ein Merkmal ändern"),
"Get a feature value": (
 "Lire une valeur de caractéristique",
 "Leer un valor de característica",
 "Einen Merkmalswert abrufen"),
"Create a feature value": (
 "Créer une valeur de caractéristique",
 "Crear un valor de característica",
 "Einen Merkmalswert anlegen"),
"Change a feature value": (
 "Modifier une valeur de caractéristique",
 "Modificar un valor de característica",
 "Einen Merkmalswert ändern"),
"List tax rules": (
    "Lister les règles de taxes",
    "Listar las reglas de impuestos",
    "Steuerregeln auflisten"),
"List files": (
 "Lister les fichiers",
 "Listar los archivos",
 "Dateien auflisten"),
"Search products": (
 "Rechercher des produits",
 "Buscar productos",
 "Produkte suchen"),
"Get a product": (
 "Lire un produit",
 "Leer un producto",
 "Ein Produkt abrufen"),
"Create a product": (
 "Créer un produit",
 "Crear un producto",
 "Ein Produkt anlegen"),
"Change a product": (
 "Modifier un produit",
 "Modificar un producto",
 "Ein Produkt ändern"),
"Switch a product on or off": (
 "Activer ou désactiver un produit",
 "Activar o desactivar un producto",
 "Ein Produkt aktivieren oder deaktivieren"),
"Delete a product": (
 "Supprimer un produit",
 "Eliminar un producto",
 "Ein Produkt löschen"),
"Get a category": (
 "Lire une catégorie",
 "Leer una categoría",
 "Eine Kategorie abrufen"),
"Create a category": (
 "Créer une catégorie",
 "Crear una categoría",
 "Eine Kategorie anlegen"),
"Change a category": (
 "Modifier une catégorie",
 "Modificar una categoría",
 "Eine Kategorie ändern"),
"Show or hide a category": (
 "Afficher ou masquer une catégorie",
 "Mostrar u ocultar una categoría",
 "Eine Kategorie ein- oder ausblenden"),
"Get the stock of a product": (
 "Lire le stock d'un produit",
 "Leer el stock de un producto",
 "Den Bestand eines Produkts abrufen"),
"Set the stock of a product": (
 "Régler le stock d'un produit",
 "Ajustar el stock de un producto",
 "Den Bestand eines Produkts festlegen"),
"List low stock": (
 "Lister les stocks bas",
 "Listar el stock bajo",
 "Niedrigen Bestand auflisten"),
"Search customers": (
 "Rechercher des clients",
 "Buscar clientes",
 "Kunden suchen"),
"Get a customer": (
 "Lire un client",
 "Leer un cliente",
 "Einen Kunden abrufen"),
"Change the status of an order": (
 "Changer le statut d'une commande",
 "Cambiar el estado de un pedido",
 "Den Status einer Bestellung ändern"),
"Set the tracking number of an order": (
 "Renseigner le numéro de suivi d'une commande",
 "Indicar el número de seguimiento de un pedido",
 "Die Sendungsnummer einer Bestellung eintragen"),
"Add a message to an order": (
 "Ajouter un message à une commande",
 "Añadir un mensaje a un pedido",
 "Einer Bestellung eine Nachricht hinzufügen"),
"Set the private note of an order": (
 "Renseigner la note privée d'une commande",
 "Indicar la nota privada de un pedido",
 "Die private Notiz einer Bestellung eintragen"),
"Record a payment for an order": (
 "Enregistrer un paiement pour une commande",
 "Registrar un pago de un pedido",
 "Eine Zahlung für eine Bestellung erfassen"),
"Issue a partial refund on an order": (
 "Effectuer un remboursement partiel sur une commande",
 "Realizar un reembolso parcial de un pedido",
 "Eine Teilerstattung für eine Bestellung vornehmen"),
"Audit the SEO of products": (
 "Auditer le SEO des produits",
 "Auditar el SEO de los productos",
 "Das SEO der Produkte prüfen"),
"Audit the SEO of categories": (
 "Auditer le SEO des catégories",
 "Auditar el SEO de las categorías",
 "Das SEO der Kategorien prüfen"),
"Audit the SEO of CMS pages": (
 "Auditer le SEO des pages CMS",
 "Auditar el SEO de las páginas CMS",
 "Das SEO der CMS-Seiten prüfen"),
"Audit the SEO of brands": (
 "Auditer le SEO des marques",
 "Auditar el SEO de las marcas",
 "Das SEO der Marken prüfen"),
"List the shop pages and their SEO": (
 "Lister les pages de la boutique et leur SEO",
 "Listar las páginas de la tienda y su SEO",
 "Die Seiten des Shops und ihr SEO auflisten"),
"Read the SEO and URL settings": (
 "Lire les réglages SEO et URL",
 "Leer los ajustes de SEO y URL",
 "Die SEO- und URL-Einstellungen lesen"),
"Read the robots.txt file": (
 "Lire le fichier robots.txt",
 "Leer el archivo robots.txt",
 "Die Datei robots.txt lesen"),
"Audit a public page of the shop": (
 "Auditer une page publique de la boutique",
 "Auditar una página pública de la tienda",
 "Eine öffentliche Seite des Shops prüfen"),
"Check the links of the shop's public pages": (
 "Vérifier les liens des pages publiques de la boutique",
 "Comprobar los enlaces de las páginas públicas de la tienda",
 "Die Links der öffentlichen Seiten des Shops prüfen"),
"Audit the shop's sitemaps": (
 "Auditer les sitemaps de la boutique",
 "Auditar los sitemaps de la tienda",
 "Die Sitemaps des Shops prüfen"),
"Compare a product page with its structured data": (
 "Comparer une page produit avec ses données structurées",
 "Comparar una página de producto con sus datos estructurados",
 "Eine Produktseite mit ihren strukturierten Daten vergleichen"),
"Audit how complete products are for answer engines": (
 "Auditer la complétude des produits pour les moteurs de réponse",
 "Auditar la completitud de los productos para los motores de respuesta",
 "Die Vollständigkeit der Produkte für Antwortmaschinen prüfen"),
"Check whether AI crawlers can read the shop": (
 "Vérifier si les robots d'IA peuvent lire la boutique",
 "Comprobar si los rastreadores de IA pueden leer la tienda",
 "Prüfen, ob KI-Crawler den Shop lesen können"),
"Audit the store policy pages": (
 "Auditer les pages de conditions de la boutique",
 "Auditar las páginas de políticas de la tienda",
 "Die Richtlinienseiten des Shops prüfen"),
"Read the llms.txt file": (
 "Lire le fichier llms.txt",
 "Leer el archivo llms.txt",
 "Die Datei llms.txt lesen"),
"Generate the robots.txt file": (
 "Générer le fichier robots.txt",
 "Generar el archivo robots.txt",
 "Die Datei robots.txt erzeugen"),
"Generate the llms.txt file": (
 "Générer le fichier llms.txt",
 "Generar el archivo llms.txt",
 "Die Datei llms.txt erzeugen"),
"Change the SEO of a CMS page": (
 "Modifier le SEO d'une page CMS",
 "Cambiar el SEO de una página CMS",
 "Das SEO einer CMS-Seite ändern"),
"Change the SEO of a shop page": (
 "Modifier le SEO d'une page de la boutique",
 "Cambiar el SEO de una página de la tienda",
 "Das SEO einer Shop-Seite ändern"),
"Change the shop's SEO settings, page titles and descriptions, robots.txt and llms.txt": (
 "Modifier les réglages SEO de la boutique, les titres et descriptions des pages, le robots.txt et le llms.txt",
 "Cambiar los ajustes de SEO de la tienda, los títulos y descripciones de las páginas, el robots.txt y el llms.txt",
 "Die SEO-Einstellungen des Shops, die Titel und Beschreibungen der Seiten, die robots.txt und die llms.txt ändern"),
"Read the statistics of this shop: revenue, orders, products sold and new customers over a period": (
 "Lire les statistiques de cette boutique : chiffre d'affaires, commandes, produits vendus et nouveaux clients sur une période",
 "Leer las estadísticas de esta tienda: volumen de negocio, pedidos, productos vendidos y nuevos clientes en un periodo",
 "Die Statistiken dieses Shops lesen: Umsatz, Bestellungen, verkaufte Produkte und Neukunden über einen Zeitraum"),
"Get a sales summary": (
 "Obtenir un résumé des ventes",
 "Obtener un resumen de las ventas",
 "Eine Verkaufsübersicht abrufen"),
"List best-selling products": (
 "Lister les meilleures ventes",
 "Listar los productos más vendidos",
 "Meistverkaufte Produkte auflisten"),
"Read the content pages of this shop: their titles, addresses and categories, displayed or not": (
 "Lire les pages de contenu de cette boutique : leurs titres, adresses et catégories, affichées ou non",
 "Leer las páginas de contenido de esta tienda: sus títulos, direcciones y categorías, mostradas o no",
 "Die Inhaltsseiten dieses Shops lesen: ihre Titel, Adressen und Kategorien, angezeigt oder nicht"),
"List content pages": (
 "Lister les pages de contenu",
 "Listar las páginas de contenido",
 "Inhaltsseiten auflisten"),
"Get a content page": (
 "Lire une page de contenu",
 "Leer una página de contenido",
 "Eine Inhaltsseite abrufen"),
"Read how the shop appears to search engines: titles, descriptions, URLs, robots.txt, sitemap and public pages": (
 "Lire comment la boutique apparaît aux moteurs de recherche : titres, descriptions, URL, robots.txt, sitemap et pages publiques",
 "Leer cómo aparece la tienda en los buscadores: títulos, descripciones, URL, robots.txt, sitemap y páginas públicas",
 "Lesen, wie der Shop für Suchmaschinen erscheint: Titel, Beschreibungen, URLs, robots.txt, Sitemap und öffentliche Seiten"),
"Audit and improve SEO": (
 "Auditer et améliorer le SEO",
 "Auditar y mejorar el SEO",
 "SEO prüfen und verbessern"),
"Find what holds the shop back in search results, fix it with the merchant's approval, and check the public pages afterwards.": (
 "Trouver ce qui freine la boutique dans les résultats de recherche, le corriger avec l'accord du marchand, puis vérifier les pages publiques.",
 "Encontrar lo que frena a la tienda en los resultados de búsqueda, corregirlo con la aprobación del comerciante y comprobar después las páginas públicas.",
 "Finden, was den Shop in den Suchergebnissen bremst, es mit Zustimmung des Händlers beheben und danach die öffentlichen Seiten prüfen."),
"Products, categories, pages or the whole shop": (
 "Produits, catégories, pages ou toute la boutique",
 "Productos, categorías, páginas o toda la tienda",
 "Produkte, Kategorien, Seiten oder der ganze Shop"),
"Get recommended by AI assistants": (
 "Être recommandé par les assistants IA",
 "Ser recomendado por los asistentes de IA",
 "Von KI-Assistenten empfohlen werden"),
"Check that answer engines and AI assistants can read the shop and trust its product data, and fix what they would miss.": (
 "Vérifier que les moteurs de réponse et les assistants IA peuvent lire la boutique et se fier à ses données produits, et corriger ce qui leur échapperait.",
 "Comprobar que los motores de respuesta y los asistentes de IA pueden leer la tienda y confiar en sus datos de producto, y corregir lo que se les escaparía.",
 "Prüfen, dass Antwortmaschinen und KI-Assistenten den Shop lesen und seinen Produktdaten vertrauen können, und beheben, was ihnen entgehen würde."),
"Name, reference or ean13 of a product to start from": (
 "Nom, référence ou ean13 d'un produit par lequel commencer",
 "Nombre, referencia o ean13 de un producto por el que empezar",
 "Name, Referenz oder EAN13 eines Produkts, mit dem begonnen wird"),
"view": (
 "voir",
 "ver",
 "ansehen"),
"add": (
 "ajouter",
 "añadir",
 "hinzufügen"),
"edit": (
 "modifier",
 "modificar",
 "bearbeiten"),
"delete": (
 "supprimer",
 "eliminar",
 "löschen"),
"Previous": (
 "Précédent",
 "Anterior",
 "Zurück"),
"Next": (
 "Suivant",
 "Siguiente",
 "Weiter"),
"Tools %from% to %to% of %total%": (
 "Outils %from% à %to% sur %total%",
 "Herramientas %from% a %to% de %total%",
 "Werkzeuge %from% bis %to% von %total%"),
"How long an assistant keeps the list of tools": (
 "Durée pendant laquelle un assistant garde la liste des outils",
 "Tiempo durante el cual un asistente conserva la lista de herramientas",
 "Wie lange ein Assistent die Werkzeugliste behält"),
"In seconds, between 10 and 3600. This shop cannot tell an assistant that the list changed - there is no connection open to it - so the assistant re-reads only when this has run out. A tool switched on or off appears or disappears after that delay, not before. Lower it while you are adding tools, raise it back afterwards. 300 by default.": (
 "En secondes, entre 10 et 3600. Cette boutique ne peut pas prévenir un assistant que la liste a changé - aucune connexion ne reste ouverte vers lui - donc il ne la relit qu'une fois ce délai écoulé. Un outil activé ou désactivé apparaît ou disparaît après ce délai, pas avant. Abaissez-le pendant que vous ajoutez des outils, remontez-le ensuite. 300 par défaut.",
 "En segundos, entre 10 y 3600. Esta tienda no puede avisar a un asistente de que la lista ha cambiado - no hay ninguna conexión abierta hacia él - así que solo la vuelve a leer cuando este plazo termina. Una herramienta activada o desactivada aparece o desaparece después de ese plazo, no antes. Redúzcalo mientras añade herramientas y vuelva a subirlo después. 300 por defecto.",
 "In Sekunden, zwischen 10 und 3600. Dieser Shop kann einem Assistenten nicht mitteilen, dass sich die Liste geändert hat - es besteht keine offene Verbindung zu ihm - daher liest er sie erst nach Ablauf dieser Zeit erneut. Ein ein- oder ausgeschaltetes Werkzeug erscheint oder verschwindet erst danach. Senken Sie den Wert beim Hinzufügen von Werkzeugen und erhöhen Sie ihn danach wieder. Standard 300."),
"Available prompts": (
 "Prompts disponibles",
 "Prompts disponibles",
 "Verfügbare Prompts"),
"Prompt": (
 "Prompt",
 "Prompt",
 "Prompt"),
"Prompts %from% to %to% of %total%": (
 "Prompts %from% à %to% sur %total%",
 "Prompts %from% a %to% de %total%",
 "Prompts %from% bis %to% von %total%"),
"No prompt is available on this shop.": (
 "Aucun prompt n'est disponible sur cette boutique.",
 "No hay ningún prompt disponible en esta tienda.",
 "Auf diesem Shop ist kein Prompt verfügbar."),
"A prompt is a guided task you start yourself from your assistant menu. It costs nothing until you pick it, then hands the assistant step-by-step instructions for using the tools. A prompt published by another module puts that module words into your assistant, which is why you can switch each one off here. A prompt is also held back where a group of the tools it guides is switched off.": (
 "Un prompt est une tâche guidée que vous lancez vous-même depuis le menu de votre assistant. Il ne coûte rien tant que vous ne le choisissez pas, puis il donne à l'assistant les instructions pas à pas pour utiliser les outils. Un prompt publié par un autre module place les mots de ce module dans votre assistant, et c'est pourquoi vous pouvez désactiver chacun ici. Un prompt est aussi retenu là où un groupe des outils qu'il guide est désactivé.",
 "Un prompt es una tarea guiada que usted mismo inicia desde el menú de su asistente. No cuesta nada mientras no lo elija; después da al asistente las instrucciones paso a paso para usar las herramientas. Un prompt publicado por otro módulo coloca las palabras de ese módulo en su asistente, y por eso puede desactivar cada uno aquí. Un prompt también queda retenido allí donde un grupo de las herramientas que guía está desactivado.",
 "Ein Prompt ist eine geführte Aufgabe, die Sie selbst aus dem Menü Ihres Assistenten starten. Er kostet nichts, solange Sie ihn nicht auswählen, und gibt dem Assistenten dann Schritt-für-Schritt-Anweisungen zur Nutzung der Werkzeuge. Ein von einem anderen Modul veröffentlichter Prompt legt die Worte dieses Moduls in Ihren Assistenten, weshalb Sie jeden einzelnen hier ausschalten können. Ein Prompt wird auch dort zurückgehalten, wo eine Gruppe der Werkzeuge, die er anleitet, ausgeschaltet ist."),
"Check the shop connection": (
 "Vérifier la connexion à la boutique",
 "Comprobar la conexión con la tienda",
 "Verbindung zum Shop prüfen"),
"Find an order": (
 "Trouver une commande",
 "Buscar un pedido",
 "Eine Bestellung finden"),
"Change an order status": (
 "Changer le statut d'une commande",
 "Cambiar el estado de un pedido",
 "Den Status einer Bestellung ändern"),
"Manage products": (
 "Gérer les produits",
 "Gestionar los productos",
 "Produkte verwalten"),
"Sales report": (
 "Rapport des ventes",
 "Informe de ventas",
 "Verkaufsbericht"),
"Report the PrestaShop shop's sales over a period: revenue, orders, average basket, best sellers.": (
 "Présenter les ventes de la boutique PrestaShop sur une période : chiffre d'affaires, commandes, panier moyen, meilleures ventes.",
 "Presentar las ventas de la tienda PrestaShop en un periodo: volumen de negocio, pedidos, cesta media, productos más vendidos.",
 "Die Verkäufe des PrestaShop-Shops über einen Zeitraum darstellen: Umsatz, Bestellungen, durchschnittlicher Warenkorb, Bestseller."),
"Period to report on, for example last month": (
 "Période à présenter, par exemple le mois dernier",
 "Periodo del informe, por ejemplo el mes pasado",
 "Berichtszeitraum, zum Beispiel der letzte Monat"),
"List modules": (
 "Lister les modules",
 "Listar los módulos",
 "Module auflisten"),
"Get a module": (
 "Lire un module",
 "Leer un módulo",
 "Ein Modul abrufen"),
"Read the modules of this shop: their version, author and whether they are installed and enabled": (
 "Lire les modules de cette boutique : leur version, leur auteur et s'ils sont installés et activés",
 "Leer los módulos de esta tienda: su versión, su autor y si están instalados y activados",
 "Die Module dieses Shops lesen: ihre Version, ihren Autor und ob sie installiert und aktiviert sind"),
"Install a module": (
 "Installer un module",
 "Instalar un módulo",
 "Ein Modul installieren"),
"Install, enable and disable the modules already present on this shop, which runs their own code": (
 "Installer, activer et désactiver les modules déjà présents sur cette boutique, ce qui exécute leur propre code",
 "Instalar, activar y desactivar los módulos ya presentes en esta tienda, lo que ejecuta su propio código",
 "Die bereits in diesem Shop vorhandenen Module installieren, aktivieren und deaktivieren, wobei ihr eigener Code ausgeführt wird"),
"Enable or disable a module": (
 "Activer ou désactiver un module",
 "Activar o desactivar un módulo",
 "Ein Modul aktivieren oder deaktivieren"),
"Review modules": (
 "Passer en revue les modules",
 "Revisar los módulos",
 "Module überprüfen"),
"See which modules the PrestaShop shop runs, their version and state, and install or switch one when asked.": (
 "Voir quels modules la boutique PrestaShop utilise, leur version et leur état, et en installer ou en basculer un sur demande.",
 "Ver qué módulos usa la tienda PrestaShop, su versión y su estado, e instalar o activar o desactivar uno cuando se pida.",
 "Sehen, welche Module der PrestaShop-Shop nutzt, ihre Version und ihren Status, und auf Wunsch eines installieren oder umschalten."),
"Name of a module": (
 "Nom d'un module",
 "Nombre de un módulo",
 "Name eines Moduls"),
"List main menu entries": (
 "Lister les entrées du menu principal",
 "Listar las entradas del menú principal",
 "Einträge des Hauptmenüs auflisten"),
"Read the performance settings": (
 "Lire les paramètres de performances",
 "Leer los ajustes de rendimiento",
 "Leistungseinstellungen lesen"),
"Read how this shop runs: its performance settings, cache and debug mode": (
 "Lire le fonctionnement de cette boutique : ses paramètres de performances, son cache et le mode debug",
 "Leer cómo funciona esta tienda: sus ajustes de rendimiento, su caché y el modo de depuración",
 "Lesen, wie dieser Shop läuft: seine Leistungseinstellungen, sein Cache und der Debug-Modus"),
"Clear the cache": (
 "Vider le cache",
 "Vaciar la caché",
 "Cache leeren"),
"Clear the cache of this shop and of every shop of the installation, which slows them until it rebuilds": (
 "Vider le cache de cette boutique et de toutes les boutiques de l'installation, ce qui les ralentit le temps qu'il se reconstruise",
 "Vaciar la caché de esta tienda y de todas las tiendas de la instalación, lo que las ralentiza hasta que se reconstruye",
 "Den Cache dieses Shops und aller Shops der Installation leeren, was sie verlangsamt, bis er neu aufgebaut ist"),
"Cache maintenance": (
 "Maintenance du cache",
 "Mantenimiento de la caché",
 "Cache-Wartung"),
"Check how the PrestaShop shop caches and clear the cache when changes do not show.": (
 "Vérifier comment la boutique PrestaShop met en cache et vider le cache quand les changements ne s'affichent pas.",
 "Comprobar cómo la tienda PrestaShop usa la caché y vaciarla cuando los cambios no se muestran.",
 "Prüfen, wie der PrestaShop-Shop zwischenspeichert, und den Cache leeren, wenn Änderungen nicht erscheinen."),
"Read how the storefront looks: the entries and links of its main menu, its theme, logos and page layouts": (
 "Lire l'apparence de la boutique : les entrées et les liens de son menu principal, son thème, ses logos et la mise en page de ses pages",
 "Leer el aspecto de la tienda: las entradas y los enlaces de su menú principal, su tema, sus logotipos y el diseño de sus páginas",
 "Lesen, wie der Shop aussieht: die Einträge und Links seines Hauptmenüs, sein Theme, seine Logos und das Layout seiner Seiten"),
"Get the theme and page layouts": (
 "Obtenir le thème et la mise en page des pages",
 "Obtener el tema y el diseño de las páginas",
 "Theme und Seitenlayouts abrufen"),
"Add a main menu entry": (
 "Ajouter une entrée au menu principal",
 "Añadir una entrada al menú principal",
 "Einen Eintrag zum Hauptmenü hinzufügen"),
"Change a main menu custom link": (
 "Modifier un lien personnalisé du menu principal",
 "Modificar un enlace personalizado del menú principal",
 "Einen eigenen Link des Hauptmenüs ändern"),
"Change how the storefront looks: add entries and custom links to its main menu, change those links, and the layout of its pages": (
 "Modifier l'apparence de la boutique : ajouter des entrées et des liens personnalisés à son menu principal, modifier ces liens, et la mise en page de ses pages",
 "Cambiar el aspecto de la tienda: añadir entradas y enlaces personalizados a su menú principal, modificar esos enlaces, y el diseño de sus páginas",
 "Das Aussehen des Shops ändern: Einträge und eigene Links zu seinem Hauptmenü hinzufügen, diese Links ändern und das Layout seiner Seiten"),
"Change the layout of a page": (
 "Modifier la mise en page d'une page",
 "Cambiar el diseño de una página",
 "Das Layout einer Seite ändern"),
"Storefront menu and layout": (
 "Menu et mise en page de la boutique",
 "Menú y diseño de la tienda",
 "Menü und Layout des Shops"),
"Read and change the main menu of the PrestaShop storefront and the page layouts of its theme.": (
 "Lire et modifier le menu principal de la boutique PrestaShop et la mise en page des pages de son thème.",
 "Leer y cambiar el menú principal de la tienda PrestaShop y el diseño de las páginas de su tema.",
 "Das Hauptmenü des PrestaShop-Shops und das Seitenlayout seines Themes lesen und ändern."),
"Read content pages": (
 "Lire les pages de contenu",
 "Leer las páginas de contenido",
 "Inhaltsseiten lesen"),
"List and read the CMS pages of the PrestaShop shop.": (
 "Lister et lire les pages CMS de la boutique PrestaShop.",
 "Listar y leer las páginas CMS de la tienda PrestaShop.",
 "Die CMS-Seiten des PrestaShop-Shops auflisten und lesen."),
"Title of a page": (
 "Titre d'une page",
 "Título de una página",
 "Titel einer Seite"),
"Check and adjust stock": (
 "Vérifier et ajuster le stock",
 "Comprobar y ajustar el stock",
 "Bestand prüfen und anpassen"),
"Find a customer": (
 "Trouver un client",
 "Buscar un cliente",
 "Einen Kunden finden"),
"Check that the connection to the PrestaShop shop works, and report what it reached - which shop, which account, which language and currency the answers are in.": (
 "Vérifier que la connexion à la boutique PrestaShop fonctionne, et indiquer ce qu'elle atteint : quelle boutique, quel compte, dans quelle langue et quelle devise arrivent les réponses.",
 "Comprobar que la conexión con la tienda PrestaShop funciona e indicar a qué llega: qué tienda, qué cuenta, en qué idioma y moneda llegan las respuestas.",
 "Prüfen, ob die Verbindung zum PrestaShop-Shop funktioniert, und melden, was sie erreicht: welcher Shop, welches Konto, in welcher Sprache und Währung die Antworten kommen."),
"Find orders in the PrestaShop shop and read one in full - search by reference, customer, date, status or amount, then open the order that matters.": (
 "Trouver des commandes dans la boutique PrestaShop et en lire une en entier : chercher par référence, client, date, statut ou montant, puis ouvrir la commande qui compte.",
 "Buscar pedidos en la tienda PrestaShop y leer uno completo: buscar por referencia, cliente, fecha, estado o importe, y abrir el pedido que importa.",
 "Bestellungen im PrestaShop-Shop finden und eine vollständig lesen: nach Referenz, Kunde, Datum, Status oder Betrag suchen und dann die relevante Bestellung öffnen."),
"Change the status of an order in the PrestaShop shop, one order at a time, with the checks that keep a customer from being emailed twice.": (
 "Changer le statut d'une commande dans la boutique PrestaShop, une commande à la fois, avec les vérifications qui évitent d'envoyer deux fois un e-mail au client.",
 "Cambiar el estado de un pedido en la tienda PrestaShop, un pedido cada vez, con las comprobaciones que evitan enviar dos veces un correo al cliente.",
 "Den Status einer Bestellung im PrestaShop-Shop ändern, eine Bestellung nach der anderen, mit den Prüfungen, die verhindern, dass ein Kunde zweimal eine E-Mail erhält."),
"Find, read and change products and categories in the PrestaShop shop, one at a time, without guessing an identifier.": (
 "Trouver, lire et modifier les produits et les catégories de la boutique PrestaShop, un à la fois, sans deviner d'identifiant.",
 "Buscar, leer y modificar los productos y las categorías de la tienda PrestaShop, uno cada vez, sin adivinar ningún identificador.",
 "Produkte und Kategorien im PrestaShop-Shop finden, lesen und ändern, eins nach dem anderen, ohne eine Kennung zu erraten."),
"See what runs low in the PrestaShop shop and set quantities, one product or combination at a time.": (
 "Voir ce qui manque dans la boutique PrestaShop et régler les quantités, un produit ou une déclinaison à la fois.",
 "Ver lo que escasea en la tienda PrestaShop y ajustar las cantidades, un producto o una combinación cada vez.",
 "Sehen, was im PrestaShop-Shop knapp wird, und Mengen festlegen, ein Produkt oder eine Kombination nach der anderen."),
"Find a customer of the PrestaShop shop, read their account and orders, and update it when asked.": (
 "Trouver un client de la boutique PrestaShop, lire son compte et ses commandes, et le modifier sur demande.",
 "Buscar un cliente de la tienda PrestaShop, leer su cuenta y sus pedidos, y modificarla cuando se pida.",
 "Einen Kunden des PrestaShop-Shops finden, sein Konto und seine Bestellungen lesen und es auf Anfrage ändern."),
"Order reference, number or customer name": (
 "Référence, numéro de commande ou nom du client",
 "Referencia, número de pedido o nombre del cliente",
 "Bestellreferenz, -nummer oder Kundenname"),
"Reference or number of the order": (
 "Référence ou numéro de la commande",
 "Referencia o número del pedido",
 "Referenz oder Nummer der Bestellung"),
"Status to set": (
 "Statut à appliquer",
 "Estado que aplicar",
 "Zu setzender Status"),
"Name, reference or EAN13 of a product": (
 "Nom, référence ou EAN13 d'un produit",
 "Nombre, referencia o EAN13 de un producto",
 "Name, Referenz oder EAN13 eines Produkts"),
"Quantity at or under which a product counts as low": (
 "Quantité à partir de laquelle un produit est considéré en stock bas",
 "Cantidad a partir de la cual un producto se considera con stock bajo",
 "Menge, ab der ein Produkt als knapp gilt"),
"Name, email or company of the customer": (
 "Nom, e-mail ou société du client",
 "Nombre, correo electrónico o empresa del cliente",
 "Name, E-Mail oder Firma des Kunden"),
"Send a file to your assistant": (
 "Envoyer un fichier à votre assistant",
 "Enviar un archivo a su asistente",
 "Eine Datei an Ihren Assistenten senden"),
"Your assistant is asking for a file. It is sent straight to your shop - the assistant never sees it.": (
 "Votre assistant demande un fichier. Il part directement vers votre boutique - l'assistant ne le voit jamais.",
 "Su asistente pide un archivo. Se envía directamente a su tienda: el asistente nunca lo ve.",
 "Ihr Assistent fragt nach einer Datei. Sie geht direkt an Ihren Shop - der Assistent sieht sie nie."),
"What for": (
 "À quoi ça sert",
 "Para qué",
 "Wofür"),
"Asked by": (
 "Demandé par",
 "Solicitado por",
 "Angefordert von"),
"Accepted": (
 "Acceptés",
 "Aceptados",
 "Zulässig"),
"up to %count% MB": (
 "jusqu'à %count% Mo",
 "hasta %count% MB",
 "bis zu %count% MB"),
"One file, once. The request is dropped after fifteen minutes.": (
 "Un fichier, une fois. La demande est abandonnée au bout de quinze minutes.",
 "Un archivo, una vez. La solicitud se descarta al cabo de quince minutos.",
 "Eine Datei, einmal. Die Anfrage verfällt nach fünfzehn Minuten."),
"Send nothing": (
 "N'envoyer aucun fichier",
 "No enviar nada",
 "Nichts senden"),
"Send this file": (
 "Envoyer ce fichier",
 "Enviar este archivo",
 "Diese Datei senden"),
"File received": (
 "Fichier reçu",
 "Archivo recibido",
 "Datei empfangen"),
"The file has been received. Go back to your assistant and let it carry on.": (
 "Le fichier a bien été reçu. Retournez dans votre assistant et laissez-le continuer.",
 "El archivo se ha recibido. Vuelva a su asistente y deje que continúe.",
 "Die Datei wurde empfangen. Kehren Sie zu Ihrem Assistenten zurück und lassen Sie ihn weitermachen."),
"Nothing was sent": (
 "Rien n'a été envoyé",
 "No se ha enviado nada",
 "Es wurde nichts gesendet"),
"No file was sent. Go back to your assistant and tell it you would rather not.": (
 "Aucun fichier n'a été envoyé. Retournez dans votre assistant et dites-lui que vous préférez ne pas le faire.",
 "No se ha enviado ningún archivo. Vuelva a su asistente y dígale que prefiere no hacerlo.",
 "Es wurde keine Datei gesendet. Kehren Sie zu Ihrem Assistenten zurück und sagen Sie ihm, dass Sie es lieber nicht möchten."),
"Nothing to send": (
 "Rien à envoyer",
 "Nada que enviar",
 "Nichts zu senden"),
"This request has expired, has already been answered, or was not meant for your account. Ask your assistant again.": (
 "Cette demande a expiré, a déjà reçu une réponse, ou ne s'adressait pas à votre compte. Redemandez à votre assistant.",
 "Esta solicitud ha caducado, ya ha sido respondida o no iba dirigida a su cuenta. Pídaselo de nuevo a su asistente.",
 "Diese Anfrage ist abgelaufen, wurde bereits beantwortet oder war nicht für Ihr Konto bestimmt. Fragen Sie Ihren Assistenten erneut."),
"No file arrived. Choose one and send it again.": (
 "Aucun fichier n'est arrivé. Choisissez-en un et renvoyez-le.",
 "No ha llegado ningún archivo. Elija uno y envíelo de nuevo.",
 "Es ist keine Datei angekommen. Wählen Sie eine aus und senden Sie sie erneut."),
"That file is too large.": (
 "Ce fichier est trop volumineux.",
 "Ese archivo es demasiado grande.",
 "Diese Datei ist zu groß."),
"That file is not one of the accepted kinds.": (
 "Ce fichier ne fait pas partie des types acceptés.",
 "Ese archivo no es de los tipos aceptados.",
 "Diese Datei gehört nicht zu den zulässigen Arten."),
"The file could not be stored. Check that the shop can write inside its modules folder.": (
 "Le fichier n'a pas pu être enregistré. Vérifiez que la boutique peut écrire dans son dossier modules.",
 "El archivo no se ha podido guardar. Compruebe que la tienda puede escribir en su carpeta de módulos.",
 "Die Datei konnte nicht gespeichert werden. Prüfen Sie, ob der Shop in seinen Modulordner schreiben kann."),

"Send files to your assistant": (
 "Envoyer des fichiers à votre assistant",
 "Enviar archivos a su asistente",
 "Dateien an Ihren Assistenten senden"),
"up to %count% files": (
 "jusqu'à %count% fichiers",
 "hasta %count% archivos",
 "bis zu %count% Dateien"),
"%size% each": ("%size% chacun", "%size% cada uno", "je %size%"),
"Already sent": ("Déjà envoyés", "Ya enviados", "Bereits gesendet"),
"Remove": ("Retirer", "Quitar", "Entfernen"),
"Add this file": ("Ajouter ce fichier", "Añadir este archivo", "Diese Datei hinzufügen"),
"You have sent everything that was asked for.": (
 "Vous avez envoyé tout ce qui était demandé.",
 "Ha enviado todo lo que se pedía.",
 "Sie haben alles gesendet, was angefordert wurde."),
"The request is dropped after fifteen minutes, and each file can only be collected once.": (
 "La demande est abandonnée au bout de quinze minutes, et chaque fichier ne peut être récupéré qu'une fois.",
 "La solicitud se descarta al cabo de quince minutos, y cada archivo solo puede recogerse una vez.",
 "Die Anfrage verfällt nach fünfzehn Minuten, und jede Datei kann nur einmal abgeholt werden."),
"You have already sent as many files as were asked for.": (
 "Vous avez déjà envoyé autant de fichiers qu'il en était demandé.",
 "Ya ha enviado tantos archivos como se pedían.",
 "Sie haben bereits so viele Dateien gesendet, wie angefordert wurden."),

"Drop your files here, or click to choose them": (
 "Déposez vos fichiers ici, ou cliquez pour les choisir",
 "Suelte sus archivos aquí, o haga clic para elegirlos",
 "Dateien hier ablegen, oder klicken zum Auswählen"),
"Drop your file here, or click to choose it": (
 "Déposez votre fichier ici, ou cliquez pour le choisir",
 "Suelte su archivo aquí, o haga clic para elegirlo",
 "Datei hier ablegen, oder klicken zum Auswählen"),
"%count% more can still be sent": (
 "encore %count% possibles",
 "aún se pueden enviar %count%",
 "noch %count% möglich"),
"Send": ("Envoyer", "Enviar", "Senden"),
"%count% files were not accepted.": (
 "%count% fichiers n'ont pas été acceptés.",
 "%count% archivos no se han aceptado.",
 "%count% Dateien wurden nicht angenommen."),

"Everything together was too large to send at once. Send fewer files at a time.": (
 "L'ensemble était trop volumineux pour partir d'un coup. Envoyez moins de fichiers à la fois.",
 "El conjunto era demasiado grande para enviarlo de una vez. Envíe menos archivos cada vez.",
 "Alles zusammen war zu groß für einen Versand. Senden Sie weniger Dateien auf einmal."),

"Configuration": (
 "Configuration",
 "Configuración",
 "Konfiguration"),
"Tools and prompts": (
 "Outils et prompts",
 "Herramientas y prompts",
 "Tools und Prompts"),
"Access and security": (
 "Accès et sécurité",
 "Acceso y seguridad",
 "Zugang und Sicherheit"),
"OAuth 2.1: every connection is approved by an employee, and an assistant never gets more rights than that employee.": (
 "OAuth 2.1 : chaque connexion est validée par un employé, et un assistant n'obtient jamais plus de droits que cet employé.",
 "OAuth 2.1: cada conexión la aprueba un empleado, y un asistente nunca obtiene más derechos que ese empleado.",
 "OAuth 2.1: Jede Verbindung wird von einem Mitarbeiter freigegeben, und ein Assistent erhält nie mehr Rechte als dieser Mitarbeiter."),
"Share your feedback": (
 "Donnez votre avis",
 "Danos tu opinión",
 "Ihr Feedback"),
"A bug, an idea, a tool you are missing? The module is built in the open on GitHub: tell us there.": (
 "Un bug, une idée, un outil qui vous manque ? Le module est développé en open source sur GitHub : dites-le-nous là-bas.",
 "¿Un error, una idea, una herramienta que echa en falta? El módulo se desarrolla en abierto en GitHub: cuéntenoslo allí.",
 "Ein Fehler, eine Idee, ein fehlendes Tool? Das Modul wird offen auf GitHub entwickelt: Sagen Sie es uns dort."),
"Meet our contributors": (
 "Découvrez nos contributeurs",
 "Conozca a nuestros colaboradores",
 "Unsere Mitwirkenden"),
"Everyone who helped build this module, as GitHub counts the commits of the published code.": (
 "Toutes les personnes qui ont contribué à ce module, d'après le décompte des commits du code publié sur GitHub.",
 "Todas las personas que han contribuido a este módulo, según el recuento de commits del código publicado en GitHub.",
 "Alle, die an diesem Modul mitgewirkt haben, gezählt nach den Commits des veröffentlichten Codes auf GitHub."),
"1 contribution": (
 "1 contribution",
 "1 contribución",
 "1 Beitrag"),
"%count% contributions": (
 "%count% contributions",
 "%count% contribuciones",
 "%count% Beiträge"),
"%percent%% of the commits": (
 "%percent% % des commits",
 "%percent% % de los commits",
 "%percent% % der Commits"),
"Become a contributor": (
 "Devenir contributeur",
 "Hazte colaborador",
 "Mitwirken"),
"Top contributor": (
 "Top contributeur",
 "Principal colaborador",
 "Top-Mitwirkender"),
"See all contributors": (
 "Voir tous les contributeurs",
 "Ver todos los colaboradores",
 "Alle Mitwirkenden ansehen"),
"Contributors %from% to %to% of %total%": (
 "Contributeurs %from% à %to% sur %total%",
 "Colaboradores %from% a %to% de %total%",
 "Mitwirkende %from% bis %to% von %total%"),
"Give feedback": (
 "Donner mon avis",
 "Dar mi opinión",
 "Feedback geben"),
"Source code": (
 "Code source",
 "Código fuente",
 "Quellcode"),
"Help and updates": (
 "Aide et mises à jour",
 "Ayuda y actualizaciones",
 "Hilfe und Updates"),
"Updates": (
 "Mises à jour",
 "Actualizaciones",
 "Updates"),
"Installed version": (
 "Version installée",
 "Versión instalada",
 "Installierte Version"),
"Latest version": (
 "Dernière version",
 "Última versión",
 "Neueste Version"),
"Unknown: GitHub could not be reached.": (
 "Inconnue : GitHub n'a pas pu être joint.",
 "Desconocida: no se ha podido contactar con GitHub.",
 "Unbekannt: GitHub war nicht erreichbar."),
"Checked on %date%": (
 "Vérifié le %date%",
 "Comprobado el %date%",
 "Geprüft am %date%"),
"Check for new versions": (
 "Rechercher les nouvelles versions",
 "Buscar nuevas versiones",
 "Nach neuen Versionen suchen"),
"Once a day, while you have this page open, the module asks GitHub for the latest published version. Nothing is downloaded or installed without you.": (
 "Une fois par jour, lorsque vous ouvrez cette page, le module demande à GitHub la dernière version publiée. Rien n'est téléchargé ni installé sans vous.",
 "Una vez al día, mientras tiene esta página abierta, el módulo consulta a GitHub la última versión publicada. No se descarga ni se instala nada sin usted.",
 "Einmal am Tag, während Sie diese Seite geöffnet haben, fragt das Modul GitHub nach der neuesten veröffentlichten Version. Ohne Sie wird nichts heruntergeladen oder installiert."),
"Check now": (
 "Vérifier maintenant",
 "Comprobar ahora",
 "Jetzt prüfen"),
"Version %version% is available.": (
 "La version %version% est disponible.",
 "La versión %version% está disponible.",
 "Version %version% ist verfügbar."),
"What is new:": (
 "Les nouveautés :",
 "Novedades:",
 "Neuerungen:"),
"and %count% more": (
 "et %count% autres",
 "y %count% más",
 "und %count% weitere"),
"Download the zip": (
 "Télécharger le zip",
 "Descargar el zip",
 "Zip herunterladen"),
"See what is new": (
 "Voir les nouveautés",
 "Ver las novedades",
 "Neuerungen ansehen"),
"To update, open Modules > Module Manager, click Upload a module and drop the zip. PrestaShop replaces the module in place and keeps your settings and connected assistants.": (
 "Pour mettre à jour, ouvrez Modules > Gestionnaire de modules, cliquez sur Installer un module et déposez le zip. PrestaShop remplace le module sur place et conserve vos réglages et vos assistants connectés.",
 "Para actualizar, abra Módulos > Gestor de módulos, haga clic en Subir un módulo y suelte el zip. PrestaShop sustituye el módulo en su sitio y conserva sus ajustes y sus asistentes conectados.",
 "Zum Aktualisieren öffnen Sie Module > Modul-Manager, klicken auf Modul hochladen und legen die Zip-Datei ab. PrestaShop ersetzt das Modul an Ort und Stelle und behält Ihre Einstellungen und verbundenen Assistenten."),
"You have the latest version.": (
 "Vous avez la dernière version.",
 "Tiene la última versión.",
 "Sie haben die neueste Version."),
"GitHub could not be reached. Try again later.": (
 "GitHub n'a pas pu être joint. Réessayez plus tard.",
 "No se ha podido contactar con GitHub. Inténtelo más tarde.",
 "GitHub war nicht erreichbar. Versuchen Sie es später erneut."),
"Built-in assistant": (
 "Assistant intégré",
 "Asistente integrado",
 "Integrierter Assistent"),
"Acts with your rights and asks before any change": (
 "Agit avec vos droits et demande avant toute modification",
 "Actúa con sus derechos y pregunta antes de cualquier cambio",
 "Handelt mit Ihren Rechten und fragt vor jeder Änderung"),
"Open the built-in assistant": (
 "Ouvrir l'assistant intégré",
 "Abrir el asistente integrado",
 "Integrierten Assistenten öffnen"),
"Close": (
 "Fermer",
 "Cerrar",
 "Schließen"),
"Module settings": (
 "Réglages du module",
 "Ajustes del módulo",
 "Moduleinstellungen"),
"Memory": (
 "Mémoire",
 "Memoria",
 "Gedächtnis"),
"Off": (
 "Désactivée",
 "Desactivada",
 "Aus"),
"Voyage key": (
 "Clé Voyage",
 "Clave de Voyage",
 "Voyage-Schlüssel"),
"Embeddings provider": (
 "Fournisseur d'embeddings",
 "Proveedor de embeddings",
 "Embeddings-Anbieter"),
"special provider for the memory": (
 "fournisseur spécial pour la mémoire",
 "proveedor especial para la memoria",
 "Spezialanbieter für das Gedächtnis"),
"Get a key at": (
 "Obtenez une clé sur",
 "Obtenga una clave en",
 "Holen Sie sich einen Schlüssel unter"),
"Stored encrypted. Leave empty to keep the saved one.": (
 "Stockée chiffrée. Laissez vide pour garder celle enregistrée.",
 "Almacenada cifrada. Deje vacío para conservar la guardada.",
 "Verschlüsselt gespeichert. Leer lassen, um den gespeicherten zu behalten."),
"Lets the assistant remember past conversations and recall them later, each one kept to the employee who had it. It embeds them with a provider - a chat provider you already set up, or Voyage - and is billed for that. Off by default.": (
 "Permet à l'assistant de se souvenir des conversations passées et de les retrouver plus tard, chacune réservée à l'employé qui l'a eue. Il les encode avec un fournisseur - un fournisseur de chat déjà configuré, ou Voyage - et c'est facturé. Désactivée par défaut.",
 "Permite al asistente recordar conversaciones pasadas y recuperarlas más tarde, cada una reservada al empleado que la tuvo. Las codifica con un proveedor - un proveedor de chat ya configurado, o Voyage - y se factura. Desactivada por defecto.",
 "Ermöglicht dem Assistenten, sich an frühere Gespräche zu erinnern und sie später abzurufen, jedes dem Mitarbeiter vorbehalten, der es geführt hat. Es kodiert sie mit einem Anbieter - einem bereits eingerichteten Chat-Anbieter oder Voyage - und das wird berechnet. Standardmäßig aus."),
"The chat providers already set up lend their key. Voyage is embeddings only, so it asks for its own key below.": (
 "Les fournisseurs de chat déjà configurés prêtent leur clé. Voyage ne fait que des embeddings, il demande donc sa propre clé ci-dessous.",
 "Los proveedores de chat ya configurados prestan su clave. Voyage solo hace embeddings, por lo que pide su propia clave abajo.",
 "Die bereits eingerichteten Chat-Anbieter leihen ihren Schlüssel. Voyage macht nur Embeddings und fragt daher unten nach seinem eigenen Schlüssel."),
"Set up a chat assistant first (OpenAI, Gemini, Mistral or Cohere), or pick Voyage, to give the memory a key.": (
 "Configurez d'abord un assistant chat (OpenAI, Gemini, Mistral ou Cohere), ou choisissez Voyage, pour donner une clé à la mémoire.",
 "Configure primero un asistente de chat (OpenAI, Gemini, Mistral o Cohere), o elija Voyage, para dar una clave a la memoria.",
 "Richten Sie zuerst einen Chat-Assistenten ein (OpenAI, Gemini, Mistral oder Cohere) oder wählen Sie Voyage, um dem Gedächtnis einen Schlüssel zu geben."),
"A key is saved. Leave empty to keep it.": (
 "Une clé est enregistrée. Laissez vide pour la garder.",
 "Hay una clave guardada. Deje vacío para conservarla.",
 "Ein Schlüssel ist gespeichert. Leer lassen, um ihn zu behalten."),
"Save this assistant with a working key first, then edit it to choose a model here.": (
 "Enregistrez d'abord cet assistant avec une clé valide, puis modifiez-le pour choisir un modèle ici.",
 "Guarde primero este asistente con una clave válida y luego edítelo para elegir un modelo aquí.",
 "Speichern Sie diesen Assistenten zuerst mit einem gültigen Schlüssel und bearbeiten Sie ihn dann, um hier ein Modell zu wählen."),
"Write your message...": (
 "Écrivez votre message…",
 "Escriba su mensaje…",
 "Schreiben Sie Ihre Nachricht…"),
"What can I do for the shop?": (
 "Que puis-je faire pour la boutique ?",
 "¿Qué puedo hacer por la tienda?",
 "Was kann ich für den Shop tun?"),
"Where shall we start?": (
 "Par où commencer ?",
 "¿Por dónde empezamos?",
 "Womit fangen wir an?"),
"How can I help you?": (
 "Comment puis-je vous aider ?",
 "¿En qué puedo ayudarle?",
 "Wie kann ich Ihnen helfen?"),
"Shall we start a new adventure?": (
 "Alors, on se lance dans une nouvelle aventure ?",
 "¿Empezamos una nueva aventura?",
 "Starten wir ein neues Abenteuer?"),
"Your shop is waiting for your instructions.": (
 "Votre boutique attend vos instructions.",
 "Su tienda espera sus instrucciones.",
 "Ihr Shop wartet auf Ihre Anweisungen."),
"What are we working on?": (
 "Sur quoi travaille-t-on ?",
 "¿En qué trabajamos?",
 "Woran arbeiten wir?"),
"New conversation": (
 "Nouvelle conversation",
 "Nueva conversación",
 "Neues Gespräch"),
"Conversations": (
 "Conversations",
 "Conversaciones",
 "Gespräche"),
"Delete this conversation": (
 "Supprimer cette conversation",
 "Eliminar esta conversación",
 "Dieses Gespräch löschen"),
"Confirmation requested": (
 "Confirmation demandée",
 "Confirmación solicitada",
 "Bestätigung erforderlich"),
"Add files or a prompt": (
 "Ajouter des fichiers ou un prompt",
 "Añadir archivos o un prompt",
 "Dateien oder einen Prompt hinzufügen"),
"Add files": (
 "Ajouter des fichiers",
 "Añadir archivos",
 "Dateien hinzufügen"),
"Images, PDF and text": (
 "Images, PDF et texte",
 "Imágenes, PDF y texto",
 "Bilder, PDF und Text"),
"Prompts": (
 "Prompts",
 "Prompts",
 "Prompts"),
"To get started": (
 "Pour commencer",
 "Para empezar",
 "Zum Einstieg"),
"1 change": (
 "1 modification",
 "1 cambio",
 "1 Änderung"),
"%count% changes": (
 "%count% modifications",
 "%count% cambios",
 "%count% Änderungen"),
"See the technical data": (
 "Voir les données techniques",
 "Ver los datos técnicos",
 "Technische Daten anzeigen"),
"Waiting for your answer...": (
 "En attente de votre réponse...",
 "Esperando su respuesta...",
 "Wartet auf Ihre Antwort..."),
"Search a conversation": (
 "Rechercher une conversation",
 "Buscar una conversación",
 "Eine Unterhaltung suchen"),
"No conversation found": (
 "Aucune conversation trouvée",
 "No se ha encontrado ninguna conversación",
 "Keine Unterhaltung gefunden"),
"Today": (
 "Aujourd'hui",
 "Hoy",
 "Heute"),
"Yesterday": (
 "Hier",
 "Ayer",
 "Gestern"),
"Earlier": (
 "Plus ancien",
 "Anteriores",
 "Älter"),
"Copy the answer": (
 "Copier la réponse",
 "Copiar la respuesta",
 "Antwort kopieren"),
"Choose the assistant": (
 "Choisir l'assistant",
 "Elegir el asistente",
 "Assistenten wählen"),
"The assistant is still finishing its previous step. Try again in a few minutes.": (
 "L'assistant termine encore son étape précédente. Réessayez dans quelques minutes.",
 "El asistente aún está terminando su paso anterior. Inténtelo de nuevo en unos minutos.",
 "Der Assistent schließt noch seinen vorherigen Schritt ab. Versuchen Sie es in ein paar Minuten erneut."),
"Cogitating...": (
 "Cogitating...",
 "Cogitating...",
 "Cogitating..."),
"Thinking...": (
 "Réflexion...",
 "Pensando...",
 "Überlege..."),
"Looking it up...": (
 "Recherche...",
 "Buscando...",
 "Suche..."),
"Checking...": (
 "Vérification...",
 "Comprobando...",
 "Prüfe..."),
"Writing...": (
 "Rédaction...",
 "Redactando...",
 "Schreibe..."),
"Which shop are we working on?": (
 "Sur quelle boutique travaille-t-on ?",
 "¿En qué tienda trabajamos?",
 "An welchem Shop arbeiten wir?"),
"The conversation stays tied to the shop you pick.": (
 "La conversation restera liée à la boutique choisie.",
 "La conversación quedará vinculada a la tienda elegida.",
 "Die Unterhaltung bleibt an den gewählten Shop gebunden."),
"Choose a shop to start": (
 "Choisissez une boutique pour commencer",
 "Elija una tienda para empezar",
 "Wählen Sie einen Shop, um zu beginnen"),
"This conversation is for the shop %shop%": (
 "Cette conversation est pour la boutique %shop%",
 "Esta conversación es para la tienda %shop%",
 "Diese Unterhaltung gilt für den Shop %shop%"),
"The assistants inherited from above were copied to this level, where they can now be changed.": (
 "Les assistants hérités du niveau supérieur ont été copiés à ce niveau, où ils peuvent maintenant être modifiés.",
 "Los asistentes heredados del nivel superior se han copiado a este nivel, donde ahora se pueden modificar.",
 "Die von der höheren Ebene geerbten Assistenten wurden auf diese Ebene kopiert und können hier nun geändert werden."),
"The assistants could not be copied to this level. Try again, or add them here by hand.": (
 "Les assistants n'ont pas pu être copiés à ce niveau. Réessayez, ou ajoutez-les ici à la main.",
 "No se han podido copiar los asistentes a este nivel. Inténtelo de nuevo o añádalos aquí manualmente.",
 "Die Assistenten konnten nicht auf diese Ebene kopiert werden. Versuchen Sie es erneut oder fügen Sie sie hier von Hand hinzu."),
"This level uses the assistants inherited from above again.": (
 "Ce niveau utilise de nouveau les assistants hérités du niveau supérieur.",
 "Este nivel vuelve a usar los asistentes heredados del nivel superior.",
 "Diese Ebene verwendet wieder die von der höheren Ebene geerbten Assistenten."),
"Use the assistants inherited from above again? The assistants of this level are removed.": (
 "Utiliser de nouveau les assistants hérités du niveau supérieur ? Les assistants de ce niveau seront supprimés.",
 "¿Volver a usar los asistentes heredados del nivel superior? Se eliminarán los asistentes de este nivel.",
 "Wieder die von der höheren Ebene geerbten Assistenten verwenden? Die Assistenten dieser Ebene werden entfernt."),
"This month: %tokens% tokens": (
 "Ce mois : %tokens% tokens",
 "Este mes: %tokens% tokens",
 "Diesen Monat: %tokens% Tokens"),
"This month: %tokens% of %cap% tokens": (
 "Ce mois : %tokens% sur %cap% tokens",
 "Este mes: %tokens% de %cap% tokens",
 "Diesen Monat: %tokens% von %cap% Tokens"),
"about %cost%, estimated": (
 "environ %cost%, estimation",
 "unos %cost%, estimación",
 "etwa %cost%, geschätzt"),
"The monthly token cap is reached. Raise it in the module settings to keep chatting.": (
 "Le plafond mensuel de tokens est atteint. Relevez-le dans les réglages du module pour continuer.",
 "Se ha alcanzado el límite mensual de tokens. Auméntelo en los ajustes del módulo para seguir.",
 "Das monatliche Token-Limit ist erreicht. Erhöhen Sie es in den Moduleinstellungen, um weiterzuchatten."),
"This assistant used its monthly tokens. Raise its cap in the module settings, or pick another assistant.": (
 "Cet assistant a utilisé ses tokens du mois. Relevez son plafond dans les réglages du module, ou choisissez un autre assistant.",
 "Este asistente ha usado sus tokens del mes. Aumente su límite en los ajustes del módulo o elija otro asistente.",
 "Dieser Assistent hat seine Tokens für den Monat verbraucht. Erhöhen Sie sein Limit in den Moduleinstellungen oder wählen Sie einen anderen Assistenten."),
"Monthly cap for this assistant": (
 "Plafond mensuel de cet assistant",
 "Límite mensual de este asistente",
 "Monatliches Limit dieses Assistenten"),
"tokens": (
 "tokens",
 "tokens",
 "Tokens"),
"Tokens it may use in a month, counted on each shop. A powerful model deserves a lower cap than an economy one. 0 for no cap of its own.": (
 "Tokens qu'il peut utiliser dans le mois, comptés sur chaque boutique. Un modèle puissant mérite un plafond plus bas qu'un modèle économique. 0 pour aucun plafond propre.",
 "Tokens que puede usar en un mes, contados en cada tienda. Un modelo potente merece un límite más bajo que uno económico. 0 para ningún límite propio.",
 "Tokens, die er in einem Monat verwenden darf, je Shop gezählt. Ein leistungsstarkes Modell verdient ein niedrigeres Limit als ein sparsames. 0 für kein eigenes Limit."),
"about": (
 "environ",
 "unos",
 "etwa"),
"This month: %tokens% tokens, counted exactly as the providers report them. The chat stops when the cap is reached, on each shop. 0 for no cap.": (
 "Ce mois : %tokens% tokens, comptés exactement comme les fournisseurs les déclarent. Le chat s'arrête quand le plafond est atteint, sur chaque boutique. 0 pour aucun plafond.",
 "Este mes: %tokens% tokens, contados exactamente como los declaran los proveedores. El chat se detiene al alcanzar el límite, en cada tienda. 0 para ningún límite.",
 "Diesen Monat: %tokens% Tokens, genau so gezählt, wie die Anbieter sie melden. Der Chat stoppt, wenn das Limit erreicht ist, je Shop. 0 für kein Limit."),
"Of these, %cached% were read back from the provider's cache, which bills them for less.": (
 "Dont %cached% relus depuis le cache du fournisseur, qui les facture moins cher.",
 "De ellos, %cached% se releyeron desde la caché del proveedor, que los factura más baratos.",
 "Davon wurden %cached% aus dem Cache des Anbieters gelesen, der sie günstiger berechnet."),
"Local assistants are not copied: each level links its own relay. Add a local assistant here to use one.": (
 "Les assistants locaux ne sont pas copiés : chaque niveau relie son propre relais. Ajoutez ici un assistant local pour en utiliser un.",
 "Los asistentes locales no se copian: cada nivel vincula su propio relé. Añada aquí un asistente local para usar uno.",
 "Lokale Assistenten werden nicht kopiert: Jede Ebene verbindet ihr eigenes Relay. Fügen Sie hier einen lokalen Assistenten hinzu, um einen zu nutzen."),
"About $%spent%, estimated from the public prices of each provider: a token does not cost the same with every model.": (
 "Environ %spent% $, estimé d'après les prix publics de chaque fournisseur : un token ne coûte pas le même prix avec chaque modèle.",
 "Unos %spent% $, estimado a partir de los precios públicos de cada proveedor: un token no cuesta lo mismo con cada modelo.",
 "Etwa %spent% $, geschätzt nach den öffentlichen Preisen jedes Anbieters: Ein Token kostet nicht bei jedem Modell gleich viel."),
"This prompt is not available.": (
 "Ce prompt n'est pas disponible.",
 "Este prompt no está disponible.",
 "Dieser Prompt ist nicht verfügbar."),
"Approve": (
 "Approuver",
 "Aprobar",
 "Genehmigen"),
"Approved": (
 "Approuvé",
 "Aprobado",
 "Genehmigt"),
"Refused": (
 "Refusé",
 "Rechazado",
 "Abgelehnt"),
"Working...": (
 "En cours…",
 "Trabajando…",
 "In Arbeit…"),
"Earlier messages were summarized to keep the cost down.": (
 "Les messages plus anciens ont été résumés pour réduire le coût.",
 "Los mensajes más antiguos se han resumido para reducir el coste.",
 "Ältere Nachrichten wurden zusammengefasst, um die Kosten zu senken."),
"The assistant is still answering. Wait for its reply.": (
 "L'assistant est encore en train de répondre. Attendez sa réponse.",
 "El asistente aún está respondiendo. Espere su respuesta.",
 "Der Assistent antwortet noch. Warten Sie seine Antwort ab."),
"Something went wrong. Try again.": (
 "Un problème est survenu. Réessayez.",
 "Algo ha fallado. Inténtelo de nuevo.",
 "Etwas ist schiefgelaufen. Versuchen Sie es erneut."),
"Powered by": (
 "Propulsé par",
 "Con la tecnología de",
 "Bereitgestellt von"),
"The key works. Model in use: %model%.": (
 "La clé fonctionne. Modèle utilisé : %model%.",
 "La clave funciona. Modelo en uso: %model%.",
 "Der Schlüssel funktioniert. Verwendetes Modell: %model%."),
"The provider refused the key or did not answer. Check the key and try again.": (
 "Le fournisseur a refusé la clé ou n'a pas répondu. Vérifiez la clé et réessayez.",
 "El proveedor ha rechazado la clave o no ha respondido. Compruebe la clave e inténtelo de nuevo.",
 "Der Anbieter hat den Schlüssel abgelehnt oder nicht geantwortet. Prüfen Sie den Schlüssel und versuchen Sie es erneut."),
"The assistant has been removed.": (
 "L'assistant a été retiré.",
 "El asistente se ha eliminado.",
 "Der Assistent wurde entfernt."),
"Assistant saved. The key works. Model in use: %model%.": (
 "Assistant enregistré. La clé fonctionne. Modèle utilisé : %model%.",
 "Asistente guardado. La clave funciona. Modelo en uso: %model%.",
 "Assistent gespeichert. Der Schlüssel funktioniert. Verwendetes Modell: %model%."),
"Assistant saved, but the provider refused the key or did not answer. Check the key.": (
 "Assistant enregistré, mais le fournisseur a refusé la clé ou n'a pas répondu. Vérifiez la clé.",
 "Asistente guardado, pero el proveedor ha rechazado la clave o no ha respondido. Compruebe la clave.",
 "Assistent gespeichert, aber der Anbieter hat den Schlüssel abgelehnt oder nicht geantwortet. Prüfen Sie den Schlüssel."),
"No assistant is switched on. Add one in the module settings.": (
 "Aucun assistant n'est activé. Ajoutez-en un dans les réglages du module.",
 "No hay ningún asistente activado. Añada uno en los ajustes del módulo.",
 "Kein Assistent ist aktiviert. Fügen Sie in den Moduleinstellungen einen hinzu."),
"Answer the change waiting for your approval first.": (
 "Répondez d'abord à la modification qui attend votre accord.",
 "Responda primero al cambio que espera su aprobación.",
 "Beantworten Sie zuerst die Änderung, die auf Ihre Genehmigung wartet."),
"The assistant could not answer. Check its key in the module settings, then try again.": (
 "L'assistant n'a pas pu répondre. Vérifiez sa clé dans les réglages du module, puis réessayez.",
 "El asistente no ha podido responder. Compruebe su clave en los ajustes del módulo e inténtelo de nuevo.",
 "Der Assistent konnte nicht antworten. Prüfen Sie seinen Schlüssel in den Moduleinstellungen und versuchen Sie es erneut."),
"Ask another assistant": (
 "Demander à un autre assistant",
 "Preguntar a otro asistente",
 "Einen anderen Assistenten fragen"),
"The files of the assistant are missing from this copy of the module. Install the module from its release zip.": (
 "Les fichiers de l'assistant manquent dans cette copie du module. Installez le module depuis le zip de sa version.",
 "Faltan los archivos del asistente en esta copia del módulo. Instale el módulo desde el zip de su versión.",
 "In dieser Kopie des Moduls fehlen die Dateien des Assistenten. Installieren Sie das Modul aus dem Zip seiner Version."),
"In development: the built-in assistant is unstable and may change or stop working without notice, and its consumption can be somewhat high.": (
 "En cours de développement : l'assistant intégré est instable et peut changer ou cesser de fonctionner sans préavis, et sa consommation peut être un peu élevée.",
 "En desarrollo: el asistente integrado es inestable y puede cambiar o dejar de funcionar sin previo aviso, y su consumo puede ser algo elevado.",
 "In Entwicklung: Der integrierte Assistent ist instabil und kann sich ohne Vorankündigung ändern oder ausfallen, und sein Verbrauch kann etwas hoch sein."),
"Edit the assistant": (
 "Modifier l'assistant",
 "Editar el asistente",
 "Assistenten bearbeiten"),
"Add an assistant": (
 "Ajouter un assistant",
 "Añadir un asistente",
 "Assistenten hinzufügen"),
"Assistant": (
 "Assistant",
 "Asistente",
 "Assistent"),
"API key": (
 "Clé API",
 "Clave API",
 "API-Schlüssel"),
"Leave empty to keep the key already saved.": (
 "Laissez vide pour garder la clé déjà enregistrée.",
 "Déjelo vacío para conservar la clave ya guardada.",
 "Leer lassen, um den bereits gespeicherten Schlüssel zu behalten."),
"The key is stored encrypted on your shop and only sent to the provider you picked.": (
 "La clé est stockée chiffrée sur votre boutique et envoyée uniquement au fournisseur choisi.",
 "La clave se guarda cifrada en su tienda y solo se envía al proveedor elegido.",
 "Der Schlüssel wird verschlüsselt in Ihrem Shop gespeichert und nur an den gewählten Anbieter gesendet."),
"Role": (
 "Rôle",
 "Función",
 "Rolle"),
"For example: SEO and product pages. Optional: with one assistant switched on, it does everything. With several, the default one hands each the work of its role.": (
 "Par exemple : SEO et fiches produits. Facultatif : avec un seul assistant activé, il fait tout. Avec plusieurs, celui par défaut confie à chacun le travail de son rôle.",
 "Por ejemplo: SEO y fichas de producto. Opcional: con un solo asistente activado, lo hace todo. Con varios, el predeterminado encarga a cada uno el trabajo de su función.",
 "Zum Beispiel: SEO und Produktseiten. Optional: Ist nur ein Assistent aktiviert, erledigt er alles. Bei mehreren gibt der Standardassistent jedem die Arbeit seiner Rolle."),
"Active": (
 "Actif",
 "Activo",
 "Aktiv"),
"Advanced. Leave empty to follow the level. Required for Ollama if your server has several models. For an assistant that makes images, an image model such as gemini-2.5-flash-image or gpt-image-1.": (
 "Avancé. Laissez vide pour suivre le niveau. Obligatoire pour Ollama si votre serveur a plusieurs modèles. Pour un assistant qui crée des images, un modèle d'images comme gemini-2.5-flash-image ou gpt-image-1.",
 "Avanzado. Déjelo vacío para seguir el nivel. Obligatorio para Ollama si su servidor tiene varios modelos. Para un asistente que crea imágenes, un modelo de imágenes como gemini-2.5-flash-image o gpt-image-1.",
 "Erweitert. Leer lassen, um der Stufe zu folgen. Für Ollama erforderlich, wenn Ihr Server mehrere Modelle hat. Für einen Assistenten, der Bilder erstellt, ein Bildmodell wie gemini-2.5-flash-image oder gpt-image-1."),
"Cancel": (
 "Annuler",
 "Cancelar",
 "Abbrechen"),
"Your assistants": (
 "Vos assistants",
 "Sus asistentes",
 "Ihre Assistenten"),
"The chat opens with the button at the top right of every back office page. It acts with the rights of the employee signed in and asks for approval before any change. One assistant switched on does everything; with several, each takes its role.": (
 "Le chat s'ouvre avec le bouton en haut à droite de chaque page du back-office. Il agit avec les droits de l'employé connecté et demande votre accord avant toute modification. Un seul assistant activé fait tout ; avec plusieurs, chacun prend son rôle.",
 "El chat se abre con el botón de arriba a la derecha de cada página del back office. Actúa con los derechos del empleado conectado y pide aprobación antes de cualquier cambio. Un solo asistente activado lo hace todo; con varios, cada uno asume su función.",
 "Der Chat öffnet sich über die Schaltfläche oben rechts auf jeder Back-Office-Seite. Er handelt mit den Rechten des angemeldeten Mitarbeiters und fragt vor jeder Änderung nach Genehmigung. Ein aktivierter Assistent erledigt alles; bei mehreren übernimmt jeder seine Rolle."),
"Model": (
 "Modèle",
 "Modelo",
 "Modell"),
"This month": (
 "Ce mois-ci",
 "Este mes",
 "Diesen Monat"),
"Default": (
 "Par défaut",
 "Predeterminado",
 "Standard"),
"Everything": (
 "Tout",
 "Todo",
 "Alles"),
"not checked yet": (
 "pas encore vérifié",
 "aún sin comprobar",
 "noch nicht geprüft"),
"Edit": (
 "Modifier",
 "Editar",
 "Bearbeiten"),
"Test the key": (
 "Tester la clé",
 "Probar la clave",
 "Schlüssel testen"),
"Make default": (
 "Mettre par défaut",
 "Establecer como predeterminado",
 "Als Standard festlegen"),
"Remove this assistant?": (
 "Retirer cet assistant ?",
 "¿Eliminar este asistente?",
 "Diesen Assistenten entfernen?"),
"No assistant yet. Add one with your own API key to get the chat.": (
 "Aucun assistant pour l'instant. Ajoutez-en un avec votre propre clé API pour avoir le chat.",
 "Todavía no hay ningún asistente. Añada uno con su propia clave API para tener el chat.",
 "Noch kein Assistent. Fügen Sie einen mit Ihrem eigenen API-Schlüssel hinzu, um den Chat zu erhalten."),
"Monthly cap, all assistants": (
 "Plafond mensuel, tous assistants",
 "Límite mensual, todos los asistentes",
 "Monatliche Grenze, alle Assistenten"),
"Instructions for the shop": (
 "Consignes de la boutique",
 "Instrucciones de la tienda",
 "Anweisungen des Shops"),
"Added to every conversation, for every assistant. For example: how to address customers, delivery times, house style.": (
 "Ajoutées à chaque conversation, pour tous les assistants. Par exemple : comment s'adresser aux clients, délais de livraison, style maison.",
 "Se añaden a cada conversación, para todos los asistentes. Por ejemplo: cómo dirigirse a los clientes, plazos de entrega, estilo de la casa.",
 "Werden jedem Gespräch für alle Assistenten hinzugefügt. Zum Beispiel: Anrede der Kunden, Lieferzeiten, Hausstil."),
"Attach files from your computer": (
 "Joindre des fichiers depuis votre ordinateur",
 "Adjuntar archivos desde su ordenador",
 "Dateien von Ihrem Computer anhängen"),
"Remove this file": (
 "Retirer ce fichier",
 "Quitar este archivo",
 "Diese Datei entfernen"),
"Up to 5 files per message.": (
 "5 fichiers au maximum par message.",
 "Hasta 5 archivos por mensaje.",
 "Höchstens 5 Dateien pro Nachricht."),
"This file cannot be sent: only images, PDF and text files up to 5 MB are accepted.": (
 "Ce fichier ne peut pas être envoyé : seuls les images, les PDF et les fichiers texte jusqu'à 5 Mo sont acceptés.",
 "Este archivo no se puede enviar: solo se aceptan imágenes, PDF y archivos de texto de hasta 5 MB.",
 "Diese Datei kann nicht gesendet werden: Es werden nur Bilder, PDF- und Textdateien bis 5 MB angenommen."),
"Images": (
 "Images",
 "Imágenes",
 "Bilder"),
"creates pictures; it never leads the chat, the default assistant asks it for them": (
 "crée des visuels ; il ne mène jamais le chat, l'assistant par défaut les lui demande",
 "crea imágenes; nunca dirige el chat, el asistente predeterminado se las pide",
 "erstellt Bilder; er führt nie den Chat, der Standardassistent bittet ihn darum"),
"Enlarge": (
 "Agrandir",
 "Ampliar",
 "Vergrößern"),
"Reduce": (
 "Réduire",
 "Reducir",
 "Verkleinern"),
"This is an AI and can make mistakes.": (
 "Ceci est une IA et peut commettre des erreurs.",
 "Esto es una IA y puede cometer errores.",
 "Dies ist eine KI und kann Fehler machen."),
"Employees allowed to use the built-in assistant": (
 "Employés autorisés à utiliser l'assistant intégré",
 "Empleados autorizados a usar el asistente integrado",
 "Mitarbeiter, die den integrierten Assistenten nutzen dürfen"),
"The chat acts with the rights of the employee using it, never more. Administrators always have it.": (
 "Le chat agit avec les droits de l'employé qui l'utilise, jamais plus. Les administrateurs y ont toujours accès.",
 "El chat actúa con los derechos del empleado que lo usa, nunca más. Los administradores siempre tienen acceso.",
 "Der Chat handelt mit den Rechten des Mitarbeiters, der ihn nutzt, nie mit mehr. Administratoren haben immer Zugriff."),
"Open source under the AFL-3.0 licence": (
 "Open source sous licence AFL-3.0",
 "Código abierto bajo licencia AFL-3.0",
 "Open Source unter der Lizenz AFL-3.0"),
"Deep thinking": (
 "Réflexion approfondie",
 "Razonamiento profundo",
 "Gründliches Nachdenken"),
"Slower, uses more tokens": (
 "Plus lent, plus de tokens",
 "Más lento, usa más tokens",
 "Langsamer, mehr Tokens"),
"Thinking": (
 "Réflexion",
 "Razonamiento",
 "Nachdenken"),
"Web search": (
 "Recherche en ligne",
 "Búsqueda en la web",
 "Websuche"),
"Web pages cited as sources": (
 "Pages web citées en source",
 "Páginas web citadas como fuente",
 "Webseiten als Quellen zitiert"),
"Remember this conversation": (
 "Retenir cette conversation",
 "Recordar esta conversación",
 "Diese Unterhaltung merken"),
"No memory": (
 "Sans mémoire",
 "Sin memoria",
 "Ohne Gedächtnis"),
"Remove %name%": (
 "Retirer %name%",
 "Quitar %name%",
 "%name% entfernen"),
"Thought it through": (
 "A réfléchi",
 "Lo ha pensado",
 "Hat nachgedacht"),
"Claude and Gemini search the web on their own. Pick one of them to search for the other assistants too, with its key: its searches count in its tokens.": (
 "Claude et Gemini font leurs recherches en ligne eux-mêmes. Choisissez l'un d'eux pour qu'il cherche aussi pour les autres assistants, avec sa clé : ses recherches comptent dans ses tokens.",
 "Claude y Gemini buscan en la web por sí mismos. Elija uno de ellos para que busque también para los demás asistentes, con su clave: sus búsquedas cuentan en sus tokens.",
 "Claude und Gemini suchen selbst im Web. Wählen Sie einen von beiden, damit er auch für die anderen Assistenten sucht, mit seinem Schlüssel: Seine Suchen zählen zu seinen Tokens."),
"Available once a Claude or Gemini assistant and an assistant of another provider are both set up.": (
 "Disponible dès qu'un assistant Claude ou Gemini et un assistant d'un autre fournisseur sont tous deux configurés.",
 "Disponible en cuanto haya configurados un asistente Claude o Gemini y un asistente de otro proveedor.",
 "Verfügbar, sobald sowohl ein Claude- oder Gemini-Assistent als auch ein Assistent eines anderen Anbieters eingerichtet sind."),
"Read the module settings": (
 "Lire les réglages du module",
 "Leer los ajustes del módulo",
 "Moduleinstellungen lesen"),
"Change a module setting": (
 "Modifier un réglage du module",
 "Cambiar un ajuste del módulo",
 "Eine Moduleinstellung ändern"),
"Find a tool": (
 "Trouver un outil",
 "Buscar una herramienta",
 "Ein Werkzeug finden"),
"This profile does not exist: the employees allowed were not changed.": (
 "Ce profil n'existe pas : les employés autorisés n'ont pas été modifiés.",
 "Este perfil no existe: los empleados autorizados no se han modificado.",
 "Dieses Profil existiert nicht: Die berechtigten Mitarbeiter wurden nicht geändert."),
"Choose an embeddings provider for the memory: without one it stays as it was.": (
 "Choisissez un fournisseur d'embeddings pour la mémoire : sans lui, elle reste comme avant.",
 "Elija un proveedor de embeddings para la memoria: sin él, se queda como estaba.",
 "Wählen Sie einen Embeddings-Anbieter für das Gedächtnis: Ohne ihn bleibt es wie zuvor."),
"The embeddings provider has no key: type the Voyage key, or pick a provider of the chat. The memory stays as it was.": (
 "Le fournisseur d'embeddings n'a pas de clé : saisissez la clé Voyage, ou choisissez un fournisseur du chat. La mémoire reste comme avant.",
 "El proveedor de embeddings no tiene clave: escriba la clave de Voyage o elija un proveedor del chat. La memoria se queda como estaba.",
 "Der Embeddings-Anbieter hat keinen Schlüssel: Geben Sie den Voyage-Schlüssel ein oder wählen Sie einen Anbieter des Chats. Das Gedächtnis bleibt wie zuvor."),
"The refresh token must last longer than the access token: the two lifetimes were not changed.": (
 "Le jeton de rafraîchissement doit durer plus longtemps que le jeton d'accès : les deux durées n'ont pas été modifiées.",
 "El token de actualización debe durar más que el token de acceso: las dos duraciones no se han modificado.",
 "Das Aktualisierungstoken muss länger gültig sein als das Zugriffstoken: Die beiden Laufzeiten wurden nicht geändert."),
"The other settings were saved.": (
 "Les autres réglages ont été enregistrés.",
 "Los demás ajustes se han guardado.",
 "Die übrigen Einstellungen wurden gespeichert."),
"The memory is switched on but does not work: its embeddings provider has no key any more. Choose a provider below and save.": (
 "La mémoire est activée mais ne fonctionne pas : son fournisseur d'embeddings n'a plus de clé. Choisissez un fournisseur ci-dessous et enregistrez.",
 "La memoria está activada pero no funciona: su proveedor de embeddings ya no tiene clave. Elija un proveedor abajo y guarde.",
 "Das Gedächtnis ist eingeschaltet, funktioniert aber nicht: Sein Embeddings-Anbieter hat keinen Schlüssel mehr. Wählen Sie unten einen Anbieter und speichern Sie."),
"Sources": (
 "Sources",
 "Fuentes",
 "Quellen"),
"The assistant could not answer with web search on. Turn it off in the + menu, or allow web search for this key in the provider's console.": (
 "L'assistant n'a pas pu répondre avec la recherche en ligne. Désactivez-la dans le menu +, ou autorisez la recherche web pour cette clé dans la console du fournisseur.",
 "El asistente no ha podido responder con la búsqueda en la web activada. Desactívela en el menú +, o permita la búsqueda web para esta clave en la consola del proveedor.",
 "Der Assistent konnte mit aktivierter Websuche nicht antworten. Schalten Sie sie im Menü + aus oder erlauben Sie die Websuche für diesen Schlüssel in der Konsole des Anbieters."),
"The relay answers. Model in use: %model%.": (
 "Le relais répond. Modèle utilisé : %model%.",
 "El relé responde. Modelo en uso: %model%.",
 "Das Relay antwortet. Verwendetes Modell: %model%."),
"The relay is offline, or the model no longer passes its tool test. Start O'So AI Relay on the computer where your AI runs, then test again.": (
 "Le relais est hors ligne, ou le modèle ne réussit plus son test des outils. Lancez O'So AI Relay sur l'ordinateur où tourne votre IA, puis testez à nouveau.",
 "El relé está desconectado, o el modelo ya no supera su prueba de herramientas. Inicie O'So AI Relay en el ordenador donde funciona su IA y vuelva a probar.",
 "Das Relay ist offline, oder das Modell besteht seinen Werkzeugtest nicht mehr. Starten Sie O'So AI Relay auf dem Computer, auf dem Ihre KI läuft, und testen Sie erneut."),
"The relay no longer offers this model, or it was unlinked. Pick a model again.": (
 "Le relais ne propose plus ce modèle, ou il a été délié. Choisissez à nouveau un modèle.",
 "El relé ya no ofrece este modelo, o se ha desvinculado. Vuelva a elegir un modelo.",
 "Das Relay bietet dieses Modell nicht mehr an, oder es wurde getrennt. Wählen Sie erneut ein Modell."),
"Local assistant saved. Model in use: %model%.": (
 "Assistant local enregistré. Modèle utilisé : %model%.",
 "Asistente local guardado. Modelo en uso: %model%.",
 "Lokaler Assistent gespeichert. Verwendetes Modell: %model%."),
"Local assistant saved, but its relay is offline right now. Start O'So AI Relay on the computer where your AI runs.": (
 "Assistant local enregistré, mais son relais est hors ligne pour l'instant. Lancez O'So AI Relay sur l'ordinateur où tourne votre IA.",
 "Asistente local guardado, pero su relé está desconectado en este momento. Inicie O'So AI Relay en el ordenador donde funciona su IA.",
 "Lokaler Assistent gespeichert, aber sein Relay ist gerade offline. Starten Sie O'So AI Relay auf dem Computer, auf dem Ihre KI läuft."),
"The local assistant could not answer. Check that O'So AI Relay and your AI are running on the computer, then try again.": (
 "L'assistant local n'a pas pu répondre. Vérifiez que O'So AI Relay et votre IA tournent sur l'ordinateur, puis réessayez.",
 "El asistente local no ha podido responder. Compruebe que O'So AI Relay y su IA están funcionando en el ordenador y vuelva a intentarlo.",
 "Der lokale Assistent konnte nicht antworten. Prüfen Sie, ob O'So AI Relay und Ihre KI auf dem Computer laufen, und versuchen Sie es erneut."),
"Edit the local assistant": (
 "Modifier l'assistant local",
 "Editar el asistente local",
 "Lokalen Assistenten bearbeiten"),
"Add a local assistant": (
 "Ajouter un assistant local",
 "Añadir un asistente local",
 "Lokalen Assistenten hinzufügen"),
"Online": (
 "En ligne",
 "En línea",
 "Online"),
"Offline": (
 "Hors ligne",
 "Desconectado",
 "Offline"),
"Local": (
 "Local",
 "Local",
 "Lokal"),
"A local assistant answers with an AI running on your own computer: Ollama, LM Studio or any software that speaks the OpenAI API. Your conversations and your shop data go to your computer, not to an AI company, and there is no key to pay for.": (
 "Un assistant local répond avec une IA qui tourne sur votre propre ordinateur : Ollama, LM Studio ou tout logiciel compatible avec l'API d'OpenAI. Vos conversations et les données de votre boutique vont sur votre ordinateur, pas chez une entreprise d'IA, et il n'y a aucune clé à payer.",
 "Un asistente local responde con una IA que funciona en su propio ordenador: Ollama, LM Studio o cualquier programa compatible con la API de OpenAI. Sus conversaciones y los datos de su tienda van a su ordenador, no a una empresa de IA, y no hay ninguna clave que pagar.",
 "Ein lokaler Assistent antwortet mit einer KI, die auf Ihrem eigenen Computer läuft: Ollama, LM Studio oder jede Software, die die OpenAI-API spricht. Ihre Unterhaltungen und Ihre Shopdaten gehen an Ihren Computer, nicht an ein KI-Unternehmen, und es gibt keinen Schlüssel zu bezahlen."),
"The link goes through O'So AI Relay, a small program that runs on your computer and calls your shop. Your shop never connects to your computer, so nothing has to be opened on your network.": (
 "La liaison passe par O'So AI Relay, un petit programme qui tourne sur votre ordinateur et appelle votre boutique. Votre boutique ne se connecte jamais à votre ordinateur : rien n'est à ouvrir sur votre réseau.",
 "El enlace pasa por O'So AI Relay, un pequeño programa que funciona en su ordenador y llama a su tienda. Su tienda nunca se conecta a su ordenador, así que no hay que abrir nada en su red.",
 "Die Verbindung läuft über O'So AI Relay, ein kleines Programm, das auf Ihrem Computer läuft und Ihren Shop aufruft. Ihr Shop verbindet sich nie mit Ihrem Computer, daher muss in Ihrem Netzwerk nichts geöffnet werden."),
"Download O'So AI Relay for your computer and run it.": (
 "Téléchargez O'So AI Relay pour votre ordinateur et lancez-le.",
 "Descargue O'So AI Relay para su ordenador y ejecútelo.",
 "Laden Sie O'So AI Relay für Ihren Computer herunter und starten Sie es."),
"Download O'So AI Relay": (
 "Télécharger O'So AI Relay",
 "Descargar O'So AI Relay",
 "O'So AI Relay herunterladen"),
"Type this code in O'So AI Relay with the address of this shop, or run this command on your computer:": (
 "Saisissez ce code dans O'So AI Relay avec l'adresse de cette boutique, ou lancez cette commande sur votre ordinateur :",
 "Introduzca este código en O'So AI Relay con la dirección de esta tienda, o ejecute este comando en su ordenador:",
 "Geben Sie diesen Code mit der Adresse dieses Shops in O'So AI Relay ein, oder führen Sie diesen Befehl auf Ihrem Computer aus:"),
"The relay of this assistant was unlinked. Remove the assistant, then add it again.": (
 "Le relais de cet assistant a été délié. Supprimez l'assistant, puis ajoutez-le à nouveau.",
 "El relé de este asistente se ha desvinculado. Elimine el asistente y vuelva a añadirlo.",
 "Das Relay dieses Assistenten wurde getrennt. Entfernen Sie den Assistenten und fügen Sie ihn erneut hinzu."),
"The relay is linked to %software%.": (
 "Le relais est lié à %software%.",
 "El relé está vinculado a %software%.",
 "Das Relay ist mit %software% verbunden."),
"The relay is linked.": (
 "Le relais est lié.",
 "El relé está vinculado.",
 "Das Relay ist verbunden."),
"Only the models that passed the relay tool test are offered: a model that cannot call tools cannot act on your shop.": (
 "Seuls les modèles qui ont réussi le test des outils du relais sont proposés : un modèle incapable d'appeler des outils ne peut pas agir sur votre boutique.",
 "Solo se ofrecen los modelos que han superado la prueba de herramientas del relé: un modelo que no puede llamar a herramientas no puede actuar en su tienda.",
 "Nur Modelle, die den Werkzeugtest des Relays bestanden haben, werden angeboten: Ein Modell, das keine Werkzeuge aufrufen kann, kann nicht in Ihrem Shop handeln."),
"The model %model% no longer passes the relay tool test: pick another one.": (
 "Le modèle %model% ne réussit plus le test des outils du relais : choisissez-en un autre.",
 "El modelo %model% ya no supera la prueba de herramientas del relé: elija otro.",
 "Das Modell %model% besteht den Werkzeugtest des Relays nicht mehr: Wählen Sie ein anderes."),
"Link O'So AI Relay": (
 "Lier O'So AI Relay",
 "Vincular O'So AI Relay",
 "O'So AI Relay verbinden"),
"Single use, valid 10 minutes. Checked automatically: nothing to click while it waits.": (
 "Usage unique, valable 10 minutes. Vérifié automatiquement : rien à cliquer pendant l'attente.",
 "Un solo uso, válido 10 minutos. Se comprueba automáticamente: no hay que hacer clic mientras espera.",
 "Einmalig, 10 Minuten gültig. Wird automatisch geprüft: Während des Wartens ist nichts zu klicken."),
"The code expired before O'So AI Relay used it.": (
 "Le code a expiré avant que O'So AI Relay ne l'utilise.",
 "El código caducó antes de que O'So AI Relay lo usara.",
 "Der Code ist abgelaufen, bevor O'So AI Relay ihn verwendet hat."),
"Retry": (
 "Réessayer",
 "Reintentar",
 "Erneut versuchen"),
"Test your AI": (
 "Tester votre IA",
 "Probar su IA",
 "Ihre KI testen"),
"Waiting for O'So AI Relay to test your AI: in the relay, type the address of your AI and click Test.": (
 "En attente du test de votre IA par O'So AI Relay : dans le relais, saisissez l'adresse de votre IA et cliquez sur Tester.",
 "Esperando a que O'So AI Relay pruebe su IA: en el relé, introduzca la dirección de su IA y haga clic en Probar.",
 "Warten, bis O'So AI Relay Ihre KI testet: Geben Sie im Relay die Adresse Ihrer KI ein und klicken Sie auf Testen."),
"Still no model able to call tools. Check that O'So AI Relay is running and that your AI passed its test, then retry.": (
 "Toujours aucun modèle capable d'appeler des outils. Vérifiez que O'So AI Relay tourne et que votre IA a réussi son test, puis réessayez.",
 "Todavía no hay ningún modelo capaz de llamar a herramientas. Compruebe que O'So AI Relay está en marcha y que su IA ha superado la prueba, y vuelva a intentarlo.",
 "Noch immer kein Modell, das Werkzeuge aufrufen kann. Prüfen Sie, ob O'So AI Relay läuft und Ihre KI den Test bestanden hat, und versuchen Sie es erneut."),
"Models able to call tools: %count%.": (
 "Modèles capables d'appeler des outils : %count%.",
 "Modelos capaces de llamar a herramientas: %count%.",
 "Modelle, die Werkzeuge aufrufen können: %count%."),
"Choose the model": (
 "Choisir le modèle",
 "Elegir el modelo",
 "Modell wählen"),
"Assistants": (
 "Assistants",
 "Asistentes",
 "Assistenten"),
"Models": (
 "Modèles",
 "Modelos",
 "Modelle"),
"Back to the assistants": (
 "Retour aux assistants",
 "Volver a los asistentes",
 "Zurück zu den Assistenten"),
"The model picked applies to this conversation. The default model is changed in the module settings.": (
 "Le modèle choisi vaut pour cette conversation. Le modèle par défaut se change dans les réglages du module.",
 "El modelo elegido vale para esta conversación. El modelo predeterminado se cambia en los ajustes del módulo.",
 "Das gewählte Modell gilt für diese Unterhaltung. Das Standardmodell wird in den Einstellungen des Moduls geändert."),
"Use": (
 "Usage",
 "Uso",
 "Verwendung"),
"Chat": (
 "Discussion",
 "Chat",
 "Chat"),
"answers in the chat and acts on the shop, with the default model chosen below": (
 "répond dans le chat et agit sur la boutique, avec le modèle par défaut choisi ci-dessous",
 "responde en el chat y actúa en la tienda, con el modelo predeterminado elegido abajo",
 "antwortet im Chat und handelt im Shop, mit dem unten gewählten Standardmodell"),
"Default model": (
 "Modèle par défaut",
 "Modelo predeterminado",
 "Standardmodell"),
"Each new conversation starts with this model; it can be changed in the chat for one conversation. The list comes from your provider.": (
 "Chaque nouvelle conversation démarre avec ce modèle ; il peut être changé dans le chat pour une conversation. La liste vient de votre fournisseur.",
 "Cada nueva conversación empieza con este modelo; se puede cambiar en el chat para una conversación. La lista viene de su proveedor.",
 "Jede neue Unterhaltung beginnt mit diesem Modell; es kann im Chat für eine Unterhaltung geändert werden. Die Liste kommt von Ihrem Anbieter."),
"List abandoned carts": (
 "Lister les paniers abandonnés",
 "Listar los carritos abandonados",
 "Verlassene Warenkörbe auflisten"),
"Get a cart rule": (
 "Lire une règle panier",
 "Leer una regla de carrito",
 "Eine Warenkorbregel abrufen"),
"List cart rules": (
 "Lister les règles panier",
 "Listar las reglas de carrito",
 "Warenkorbregeln auflisten"),
"Create a catalogue price rule": (
 "Créer une règle de prix catalogue",
 "Crear una regla de precios de catálogo",
 "Katalogpreisregel erstellen"),
"List catalogue price rules": (
 "Lister les règles de prix catalogue",
 "Listar las reglas de precios de catálogo",
 "Katalogpreisregeln auflisten"),
"List shop search terms": (
 "Lister les recherches de la boutique",
 "Listar las búsquedas de la tienda",
 "Suchbegriffe des Shops auflisten"),
"List products viewed but not sold": (
 "Lister les produits vus mais non vendus",
 "Listar los productos vistos pero no vendidos",
 "Angesehene, aber nicht verkaufte Produkte auflisten"),
"List products never purchased": (
 "Lister les produits jamais achetés",
 "Listar los productos nunca comprados",
 "Nie gekaufte Produkte auflisten"),
"Sales - read": (
 "Ventes - lecture",
 "Ventas - lectura",
 "Verkauf - lesen"),
"Read abandoned carts, searches, product views and promotions": (
 "Lire les paniers abandonnés, les recherches, les produits consultés et les promotions",
 "Leer los carritos abandonados, las búsquedas, los productos vistos y las promociones",
 "Verlassene Warenkörbe, Suchanfragen, angesehene Produkte und Aktionen lesen"),
"Create a cart rule": (
 "Créer une règle panier",
 "Crear una regla de carrito",
 "Eine Warenkorbregel erstellen"),
"Change a cart rule": (
 "Modifier une règle panier",
 "Modificar una regla de carrito",
 "Eine Warenkorbregel ändern"),
"Switch a cart rule on or off": (
 "Activer ou désactiver une règle panier",
 "Activar o desactivar una regla de carrito",
 "Eine Warenkorbregel aktivieren oder deaktivieren"),
"Sales - changes": (
 "Ventes - modifications",
 "Ventas - cambios",
 "Verkauf - Änderungen"),
"Create and change cart rules and catalogue price rules": (
 "Créer et modifier les règles panier et les règles de prix catalogue",
 "Crear y modificar las reglas de carrito y las reglas de precios del catálogo",
 "Warenkorbregeln und Katalogpreisregeln erstellen und ändern"),
"Boost sales": (
 "Booster les ventes",
 "Impulsar las ventas",
 "Verkäufe steigern"),
"Find the products, searches and carts where sales are lost, and set up promotions with the merchant's approval.": (
 "Trouver les produits, les recherches et les paniers où des ventes se perdent, et mettre en place des promotions avec l'accord du marchand.",
 "Encontrar los productos, las búsquedas y los carritos donde se pierden ventas, y crear promociones con la aprobación del comerciante.",
 "Die Produkte, Suchanfragen und Warenkörbe finden, bei denen Verkäufe verloren gehen, und Aktionen mit Zustimmung des Händlers einrichten."),
"Number of days to look back, 30 by default": (
 "Nombre de jours à remonter, 30 par défaut",
 "Número de días hacia atrás, 30 por defecto",
 "Anzahl der Tage zurück, standardmäßig 30"),
}
LANGS = [("fr-FR", 0), ("es-ES", 1), ("de-DE", 2)]
BASE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "translations")

def unit(src, target):
    return (
        "    <!-- %s -->\n"
        "    <trans-unit id=\"%s\" resname=\"%s\">\n"
        "      <source>%s</source>\n"
        "      <target>%s</target>\n"
        "    </trans-unit>\n"
    ) % (
        html.escape(src, quote=False),
        hashlib.md5(src.encode("utf-8")).hexdigest(),
        html.escape(src, quote=True),
        html.escape(src, quote=False),
        html.escape(target, quote=False),
    )

def write(locale, index):
    body = "".join(unit(src, values[index]) for src, values in T.items())
    xliff = (
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        '<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">\n'
        '  <file original="ModulesSomcpAdmin" source-language="en-US" target-language="%s" datatype="plaintext">\n'
        "    <body>\n%s    </body>\n"
        "  </file>\n"
        "</xliff>\n"
    ) % (locale, body)
    path = os.path.join(BASE, locale, "ModulesSomcpAdmin.%s.xlf" % locale)
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(xliff)
    return path

def write_source():
    body = "".join(unit(src, src) for src in T)
    xliff = (
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        '<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">\n'
        '  <file original="ModulesSomcpAdmin" source-language="en-US" target-language="en-US" datatype="plaintext">\n'
        "    <body>\n%s    </body>\n"
        "  </file>\n"
        "</xliff>\n"
    ) % body
    path = os.path.join(BASE, "en-US", "ModulesSomcpAdmin.en-US.xlf")
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(xliff)
    return path

if __name__ == "__main__":
    written = [write_source()] + [write(locale, index) for locale, index in LANGS]
    print(json.dumps({"strings": len(T), "files": written}, indent=1))
