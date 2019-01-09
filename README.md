# MQTT Bridge (pull)

In php written script to pull data from several extractor classes and push them to MQTT according configuration. 

Currently there exist only two classes for pulling data from Kostal Solar inverters and Dimplex-Type Heatpumps. There is no official support for MQTT for both of them. 

I run the script via cron just all two minutes, which suits for me. 

It can be used for example for integration into home assistant or similar.

## Usage

Usage is quite simple, when having a linux system to it. I run it on a raspberry pi like device. 

When not yet started it creates a configuration file under your user account directory and asks you to adapt it for your needs. 

Enter your broker´s information and add the extractors you want to use:
```
# extractors start with "1" and need to have a continuous numbering:
# extractorN=target/topic/root/to/send/to/#,extractorClass,hostname(,user,pass)
extractor1=home/solarinverter1/#,picoSolar,inverterhost
extractor2=home/heatpump/#,weishauptWP,heatpumphost,hpuser,hppass
```

The heatpump must have the network module enabled, plus have added a custom file for extracting the data from. Please contact me if you need help to setup.
