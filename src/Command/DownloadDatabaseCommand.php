<?php
namespace App\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Sunra\PhpSimple\HtmlDomParser;
use App\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;

class DownloadDatabaseCommand extends Command
{
    public function __construct(?string $name = null, EntityManagerInterface $em)
    {
        parent::__construct($name);

        $this->em = $em;
    }

    protected function configure()
    {
        $this
            ->setName('app:download-database')
            ->setDescription('Download and persist companies data from panoramafirm.pl');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $client = new Client();
        $output->writeln('<comment>Connecting for categories</comment>');
        $dom = $this->getDom($client, 'https://panoramafirm.pl/branze/lista.html');
        $output->writeln('<info>Categories downloaded</info>');
        $categories = $this->getCategories($dom);
        $categoriesLinks = $this->getCategoriesLinks($categories);

        foreach ($categoriesLinks as $categoryLink) {
            $output->writeln('<comment>Connecting for subcategories</comment>');
            $dom = $this->getDom($client, $categoryLink);
            $output->writeln('<info>Subategories downloaded</info>');
            $subCategories = $this->getSubCategories($dom);
            $subCategoriesLinks = $this->getSubCategoriesLinks($subCategories);
            foreach ($subCategoriesLinks as $subCategoryLink) {
                for ($i = 2; $i <= 1000; $i++) {
                    $link = $subCategoryLink . '/firmy,' . $i . '.html';
                    try {
                        $output->writeln('<comment>Connecting for company</comment>');
                        $res = $client->request('GET', $link);
                        $output->writeln('<info>Company downloaded</info>');

                    } catch (RequestException $ex) {
                        break;
                    }
                    $body = $res->getBody();
                    $content = $body->getContents();
                    $dom = HtmlDomParser::str_get_html($content);
                    sleep(10);
                    $bussinesCards = $this->getBussinesCards($dom);
                    foreach ($bussinesCards as $bussinesCard) {
                        $company = new Company();
                        $name = trim($bussinesCard->find('.business-card-title', 0)->innertext);
                        $website = trim($bussinesCard->find('.addax-cs_hl_hit_homepagelink_click', 0)->href);
                        $email = trim($bussinesCard->find('.addax-cs_hl_email_submit_click', 0)->href);
                        $company->setName($name);
                        $company->setWebsite($website);
                        $company->setEmail($email);

                        $this->em->persist($company);
                        $this->em->flush($company);

                    }
                }

            }
        }
    }

    private function getDom($client, $link)
    {
        $res = $client->request('GET', $link);
        $body = $res->getBody();
        $content = $body->getContents();
        $dom = HtmlDomParser::str_get_html($content);
        sleep(5);
        return $dom;

    }

    private function getCategories($dom) : array
    {
        $categoryLinks = $dom->find('#categoryListTree', 0)->find('a');
        return $categoryLinks;
    }

    private function getCategoriesLinks(array $categories) : array
    {
        foreach ($categories as $category) {
            $array[] = $category->href;
        }
        return $array;
    }

    private function getSubCategories($dom)
    {
        $subCategories = $dom->find('.cl-article', 0)->find('a');
        return $subCategories;
    }

    private function getSubCategoriesLinks(array $subCategories) : array
    {
        foreach ($subCategories as $subCategory) {
            $array[] = $subCategory->href;
        }
        return $array;
    }

    private function isPageWithoutBussinesCards($dom)
    {
        return strpos('Brak wyników', $dom);
    }


    private function getBussinesCards($dom)
    {
        $bussinesCard = $dom->find('.business-card');
        return $bussinesCard;
    }



}