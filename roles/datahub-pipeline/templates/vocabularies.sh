#!/bin/bash

FILES=('PIDS_ALL_UTF8' 'RIGHTS' 'CREATORS_UTF8' 'AAT_UTF8' 'CA_UTF8' 'Copyrights' 'Translations')

for file in "${FILES[@]}"; do
  echo "Generating $file.";
  PERL5LIB=~{{ application_user }}/perl5/lib/perl5/ catmandu import CSV to DBI --data_source "dbi:SQLite:/tmp/import.${file}.sqlite" < "{{ datahub_pipeline.dir }}/authority_files/${file}.csv"
done
