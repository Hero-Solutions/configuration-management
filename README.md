# Configuration management

Configuration and management of a Datahub/Imagehub/Arthub installation via Ansible.

# Considerations

This Ansible setup currently only works on Debian-based controlled machines. At the time of writing, the following distributions are supported:
* Debian >= 10
* Ubuntu >= 20.04

The controlled machine must also have an application user with sudo privileges along with Python installed.

NOTE: for the Arthub, openjdk-11-jre version 11.0.9.1 does not work with solr, so make sure that either version 8 or version 11.0.11 is installed.
On Debian 10, do this by adding the security repos!

For ResourceSpace and the RS_PTIF extensions, it is important to have adequate space in the /tmp folder (at root level) in order to perform image operatations. If not, it is recommended to add the following line to `include/config.php` in your ResourceSpace setup:
```
$tempdir = tmp_dir_location; # tmp_dir_location can be /opt/tmp/ for example (must be chowned to www-data:www-data)
```

# Requirements

To use this Ansible playbook for an installation of the Flemish Art Collection's ecosystem, you need to have a controller (could be either a PC or server) with the following packages installed:

* Ansible >= 2.10
* python-bcrypt
* python-passlib
* python-argcomplete (activate this by running the command `activate-global-python-argcomplete` as root user)

Along with the following Ansible collections:

* community.mysql
* community.mongodb
* community.general
* geerlingguy.composer
* rvm.ruby

Set the Ansible hash behavior strategy to 'merge' by ensuring the following line is present in `/etc/ansible/ansible.cfg` (assuming your Ansible configuration is in `/etc/ansible`):
```
hash_behaviour = merge
```

On a Debian-based control machine, you can provide all necessary packages and collections listed above by executing the following commands as root:
```
apt update
apt install software-properties-common
apt-add-repository --yes --update ppa:ansible/ansible
apt install ansible
apt install python-bcrypt python-passlib
apt install python-argcomplete
activate-global-python-argcomplete
ansible-galaxy collection install community.mysql
ansible-galaxy collection install community.mongodb
ansible-galaxy collection install community.general
ansible-galaxy install geerlingguy.composer
ansible-galaxy install rvm.ruby
vim /etc/ansible/ansible.cfg
```

# Usage

Before installing the server, it is best to have the appropriate DNS records with the domain name(s) that you plan on hosting on this machine. If the used domain names do not yet exist when executing the play, LetsEncrypt will fail to install its certificates. Note: the certificates are installed at the very end of the run, so everything else will be set up properly, but you will have to run the play again after the DNS records are made in order to setup the necessary SSL certificates.
This does not apply if you use have SSL certificates of your own and do not plan to use LetsEncrypt.

To install the Datahub, make sure you have a Gmail account with 2-factor authentication enabled. You can do this by browsing to https://myaccount.google.com and clicking the security tab. Then set up an app password (Security tab > App passwords), under 'Select app' choose 'Other (Custom name)', enter 'Datahub' and click 'Generate'. Save this password, we will need it to set up the Datahub admin account. We will refer to this password later on with `gmail_app_password` (and your gmail account with `my_name@gmail.com`).

Assuming your Ansible configuration is found in `/etc/ansible` on your control machine, you will need to make a new `hosts` file and a `host_var` folder in a new environment as described below. In this example, we will assume you want to name your environment "vkc", the IP of the controlled machine is "100.100.100.100" and you want to give your machine the alias "vkc-ecosystem".

Folder structure:

```
/etc/ansible/environments
├── vkc
│   ├── hosts
│   └── host_vars
|       └── vkc-ecosystem
```

If you want to install everything (Datahub, Dashboard, Arthub, ResourceSpace, Cantaloupe, Imagehub and Condition reporting tool), then your `hosts` file will look like this:

```
all:
  hosts:
    vkc-ecosystem:
      ansible_host: 100.100.100.100
  children:
    datahub:
      hosts:
        vkc-ecosystem
    datahub_pipeline:
      hosts:
        vkc-ecosystem
    datahub_dashboard:
      hosts:
        vkc-ecosystem
    arthub:
      hosts:
        vkc-ecosystem
    resourcespace:
      hosts:
        vkc-ecosystem
    cantaloupe:
      hosts:
        vkc-ecosystem
    imagehub:
      hosts:
        vkc-ecosystem
    condition_reports:
      hosts:
        vkc-ecosystem
```

You will need to provide a number of configuration parameters, depending on your setup. If the naming of the applications and systemd services do not matter and you're okay with everything being installed in `/opt` (and ResourceSpace in `/var/www`), then below is the bare minimum that you need to provide in your `host_vars` (in this case, in `/etc/ansible/environments/vkc/host_vars/vkc-ecosystem`. Additional configurable options can be found in `group_vars/all`.

```
letsencrypt_email: ops@example.com
application_user: vkc
application_password: vkc_password
ansible_user: vkc
ansible_password: vkc_password
ansible_sudo_pass: vkc_password
mariadb_root_password: mariadb_root_password
datahub:
  service_organisation: Organization name
  service_organisation_website: https://www.example.com
  service_email: datahub@example.com
  contact_email: info@example.com
  nginx:
    server_name: datahub.example.com
  mongodb:
    root_username: vkc
    root_password: mongodb_root_password
    password: datahub_password
  mailer:
    username: my_name@gmail.com
    password: gmail_app_password
    delivery_address: my_name@gmail.com
  admin:
    username: admin
    password: datahub_admin_password
    first_name: Admin first name
    last_name: Admin last name
    email: datahub_admin@example.com
  consumer:
    password: datahub_consumer_password
    email: datahubconsumer@example.com
datahub_pipeline:
  eiz:
    username: erfgoedinzicht_username
    password: erfgoedinzicht_password
datahub_dashboard:
  service_email: dashboard@example.com
  datahub_url: https://datahub.example.com/oai
  nginx:
    server_name: dashboard.example.com
  mongodb:
    root_username: vkc
    root_password: mongodb_root_password
    password: dashboard_user_password
arthub:
  nginx:
    server_name: arthub.example.com
resourcespace:
  base_url: https://resourcespace.example.com
  admin:
    full_name: ResourceSpace Admin
    email: rs_admin@example.com
    username: admin
    password: resourcespace_admin_password
    from_email: resourcespace@example.com
  mysql:
    password: resourcespace_mysql_password
    ro_password: resourcespace_readonly_mysql_password
  nginx:
    server_name: resourcespace.example.com
cantaloupe:
  nginx:
    server_name: images.example.com
imagehub:
  public: true
  app_secret: imagehub_app_secret_md5
  resourcespace_api_url: https://resourcespace.example.com/api/
  resourcespace_api_username: admin
  datahub_url: https://datahub.example.com
  credit_suffix_nl: Organization
  credit_suffix_en: Organization
  cantaloupe_url: https://images.example.com/iiif/2/
  service_url: https://imagehub.example.com/iiif/2/
  adfs_key: RoleName
  adfs_values:
    - ResourceGroupUser
    - Archivist
    - Admin
    - Superadmin
  authentication_url: https://imagehub.example.com/public/authenticate
  auth_description: 'Organization requires that you log in with your organisation account to view this content.'
  auth_label: 'Login to Organization'
  mysql:
    password: imagehub_mysql_password
  nginx:
    server_name: imagehub.example.com
condition_reports:
  service_url: https://conditionreports.example.com/iiif/3/
  admin:
    email: admin@example.com
    full_name: Firstname Lastname
    password_hash: condition_reports_admin_password_hash
  nginx:
    server_name: conditionreports.example.com
```

It is recommended to randomly generate the following passwords (the longer the better, minimum 16 characters recommended):
* mariadb_root_password (if you are not running any MariaDB instance on the controlled machine yet)
* mongodb_root_password (make sure you use the same password for both `datahub` and `datahub_dashboard`)
* dashboard_user_password
* datahub_admin_password
* datahub_consumer_password
* dashboard_user_password
* resourcespace_admin_password
* resourcespace_mysql_password
* resourcespace_readonly_mysql_password
* imagehub_app_secret_md5 (this ought be a random MD5 hash)
* imagehub_mysql_password
* condition_reports_admin_password_hash (must be a Bcrypt password hash, you can generate one at https://bcrypt.online; use cost factor 13)

After you are happy about the configuration of your host_vars, you can execute the play on the control machine to install everything on the controlled machine:
```
ansible-playbook -i /etc/ansible/environments/vkc play.yml
```
